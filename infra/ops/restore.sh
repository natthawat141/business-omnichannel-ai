#!/usr/bin/env bash
# Replace live Management and Chatwoot data from one backup stamp.
# Writers stay stopped when this script exits so an operator can inspect
# the databases before serving traffic again.
set -euo pipefail
umask 077

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
# shellcheck source=lib.sh
source "$root/infra/ops/lib.sh"
# shellcheck source=s3.sh
source "$root/infra/ops/s3.sh"

ALPINE_IMAGE="${ALPINE_IMAGE:-alpine:3.22}"
config_file="$root/runtime/backup.env"
stamp="${1:-}"
work=""

cleanup() {
  if [[ -n "$work" && -d "$work" ]]; then
    rm -rf "$work"
  fi
  if [[ -n "${S3_CONFIG_DIR:-}" && -d "$S3_CONFIG_DIR" ]]; then
    rm -rf "$S3_CONFIG_DIR"
  fi
}
trap cleanup EXIT INT TERM

if [[ "${1:-}" == "--check" ]]; then
  load_backup_config "$config_file"
  echo "restore_config_ok"
  exit 0
fi

if ! is_backup_stamp "$stamp"; then
  echo "restore_refused reason=stamp" >&2
  exit 1
fi
if [[ "${RESTORE_CONFIRM:-}" != "replace-live-data" ]]; then
  echo "restore_refused reason=confirm" >&2
  exit 1
fi

load_backup_config "$config_file"
[[ -f "$root/.env" ]] || { echo "restore_failed step=app-env" >&2; exit 1; }

mysql_root="$(read_env_value "$root/.env" MANAGEMENT_DB_ROOT_PASSWORD || true)"
mysql_database="$(read_env_value "$root/.env" MANAGEMENT_DB_DATABASE || true)"
pg_user="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_USERNAME || true)"
pg_password="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_PASSWORD || true)"
pg_database="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_DATABASE || true)"
if [[ -z "$mysql_root" || -z "$mysql_database" || -z "$pg_user" || -z "$pg_password" || -z "$pg_database" ]]; then
  echo "restore_failed step=app-env" >&2
  exit 1
fi
if ! is_sql_identifier "$mysql_database" || ! is_sql_identifier "$pg_user" || ! is_sql_identifier "$pg_database"; then
  echo "restore_failed step=app-env" >&2
  exit 1
fi

project="$(compose_project_name "$root/compose.yml")"
work="$(mktemp -d "$root/runtime/restore-work.XXXXXX")"
S3_WORKDIR="$work"
prepare_aws_config
docker pull "$AWS_CLI_IMAGE" >/dev/null
docker pull "$ALPINE_IMAGE" >/dev/null

s3 s3 cp "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${stamp}/" /work/ --recursive --only-show-errors
(
  cd "$work"
  sha256sum -c checksums.sha256
)

for name in management-mysql.sql.gz chatwoot-postgres.dump checksums.sha256; do
  [[ -s "$work/$name" ]] || { echo "restore_failed step=missing-${name}" >&2; exit 1; }
done

docker compose stop ai ai-worker management-php management-nginx chatwoot-rails chatwoot-sidekiq

docker compose exec -T \
  -e MYSQL_PWD="$mysql_root" \
  management-mysql \
  mysql --user=root --execute="DROP DATABASE IF EXISTS \`${mysql_database}\`; CREATE DATABASE \`${mysql_database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

gunzip -c "$work/management-mysql.sql.gz" | docker compose exec -T \
  -e MYSQL_PWD="$mysql_root" \
  management-mysql \
  mysql --user=root "$mysql_database"

docker compose exec -T \
  -e PGPASSWORD="$pg_password" \
  chatwoot-postgres \
  psql --username="$pg_user" --dbname=postgres \
  --command="SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${pg_database}' AND pid <> pg_backend_pid();"

docker compose exec -T \
  -e PGPASSWORD="$pg_password" \
  chatwoot-postgres \
  pg_restore --clean --if-exists --no-owner --no-acl \
  --username="$pg_user" --dbname="$pg_database" < "$work/chatwoot-postgres.dump"

restore_volume() {
  local volume="$1" archive="$2"
  [[ -s "$work/$archive" ]] || return 0
  docker run --rm \
    -v "${volume}:/dest" \
    -v "${work}:/in:ro" \
    "$ALPINE_IMAGE" \
    sh -c 'find /dest -mindepth 1 -delete && tar -C /dest -xzf "/in/'"${archive}"'"'
}

if [[ -s "$work/chatwoot-storage.tar.gz" ]]; then
  restore_volume "${project}_chatwoot_storage" "chatwoot-storage.tar.gz"
fi
if [[ -s "$work/management-storage.tar.gz" ]]; then
  restore_volume "${project}_management_storage" "management-storage.tar.gz"
fi

if [[ "${RESTORE_REDIS:-}" == "yes" && -s "$work/chatwoot-redis-data.tar.gz" ]]; then
  docker compose stop chatwoot-redis
  restore_volume "${project}_chatwoot_redis" "chatwoot-redis-data.tar.gz"
  docker compose start chatwoot-redis
else
  echo "restore_redis_skipped"
fi

echo "restore_ok stamp=${stamp}"
echo "writers_stopped ai ai-worker management-php management-nginx chatwoot-rails chatwoot-sidekiq"
echo "start_when_ready: docker compose start ai ai-worker management-php management-nginx chatwoot-rails chatwoot-sidekiq"
