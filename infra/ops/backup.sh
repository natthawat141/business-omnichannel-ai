#!/usr/bin/env bash
# Daily logical backup of the one-VM stack to a private S3-compatible bucket.
# Database dumps are the restore source. Attachment archives are best-effort
# copies taken while the services are still running.
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
runtime_dir="$root/runtime"
work=""

fail_step() {
  local step="$1"
  mkdir -p "$runtime_dir"
  marker_line "$step" > "$runtime_dir/backup-last-failure"
  chmod 600 "$runtime_dir/backup-last-failure"
  echo "backup_failed step=${step}" >&2
  exit 1
}

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
  docker compose version >/dev/null
  echo "backup_config_ok"
  exit 0
fi

load_backup_config "$config_file" || fail_step config
[[ -f "$root/.env" ]] || fail_step app-env

mkdir -p "$runtime_dir"
exec 9>"$runtime_dir/backup.lock"
if ! flock -n 9; then
  echo "backup_already_running"
  exit 0
fi

mysql_user="$(read_env_value "$root/.env" MANAGEMENT_DB_USERNAME || true)"
mysql_password="$(read_env_value "$root/.env" MANAGEMENT_DB_PASSWORD || true)"
mysql_database="$(read_env_value "$root/.env" MANAGEMENT_DB_DATABASE || true)"
pg_user="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_USERNAME || true)"
pg_password="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_PASSWORD || true)"
pg_database="$(read_env_value "$root/.env" CHATWOOT_POSTGRES_DATABASE || true)"
if [[ -z "$mysql_user" || -z "$mysql_password" || -z "$mysql_database" || -z "$pg_user" || -z "$pg_password" || -z "$pg_database" ]]; then
  fail_step app-env
fi
if ! is_sql_identifier "$mysql_user" || ! is_sql_identifier "$mysql_database" || ! is_sql_identifier "$pg_user" || ! is_sql_identifier "$pg_database"; then
  fail_step app-env
fi

project="$(compose_project_name "$root/compose.yml")" || fail_step compose-name
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
is_backup_stamp "$stamp" || fail_step stamp
work="$runtime_dir/backup-work/${stamp}"
mkdir -p "$work"
S3_WORKDIR="$work"

docker pull "$AWS_CLI_IMAGE" >/dev/null || fail_step image
docker pull "$ALPINE_IMAGE" >/dev/null || fail_step image

archive_volume() {
  local volume="$1" dest_name="$2"
  docker run --rm \
    -v "${volume}:/source:ro" \
    -v "${work}:/out" \
    "$ALPINE_IMAGE" \
    tar -C /source -czf "/out/${dest_name}" .
}

if ! docker compose exec -T \
  -e MYSQL_PWD="$mysql_password" \
  management-mysql \
  mysqldump --single-transaction --no-tablespaces --skip-lock-tables \
  --user="$mysql_user" "$mysql_database" | gzip -c > "$work/management-mysql.sql.gz"; then
  fail_step dump-mysql
fi
[[ -s "$work/management-mysql.sql.gz" ]] || fail_step dump-mysql

if ! docker compose exec -T \
  -e PGPASSWORD="$pg_password" \
  chatwoot-postgres \
  pg_dump --format=custom --no-owner --no-acl \
  --username="$pg_user" --dbname="$pg_database" > "$work/chatwoot-postgres.dump"; then
  fail_step dump-postgres
fi
[[ -s "$work/chatwoot-postgres.dump" ]] || fail_step dump-postgres

archive_volume "${project}_chatwoot_redis" "chatwoot-redis-data.tar.gz" || fail_step archive-redis
[[ -s "$work/chatwoot-redis-data.tar.gz" ]] || fail_step archive-redis

expected=(management-mysql.sql.gz chatwoot-postgres.dump chatwoot-redis-data.tar.gz)
if [[ "$BACKUP_INCLUDE_FILES" == "true" ]]; then
  archive_volume "${project}_chatwoot_storage" "chatwoot-storage.tar.gz" || fail_step archive-chatwoot
  archive_volume "${project}_management_storage" "management-storage.tar.gz" || fail_step archive-management
  [[ -s "$work/chatwoot-storage.tar.gz" && -s "$work/management-storage.tar.gz" ]] || fail_step archive-files
  expected+=(chatwoot-storage.tar.gz management-storage.tar.gz)
fi

(
  cd "$work"
  sha256sum "${expected[@]}" > checksums.sha256
)
expected+=(checksums.sha256)

prepare_aws_config
if ! s3 s3 cp /work/ "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${stamp}/" --recursive --only-show-errors; then
  fail_step upload
fi

listing="$(s3 s3 ls "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${stamp}/" )" || fail_step verify
for name in "${expected[@]}"; do
  if ! grep -F -q -- "$name" <<<"$listing"; then
    fail_step verify
  fi
done

cutoff="$(date -u -d "${BACKUP_RETENTION_DAYS} days ago" +%s)"
prefixes="$(s3 s3api list-objects-v2 \
  --bucket "$BACKUP_S3_BUCKET" \
  --prefix "${BACKUP_S3_PREFIX}/" \
  --delimiter "/" \
  --query 'CommonPrefixes[].Prefix' \
  --output text)" || fail_step retention
if ! expired="$(printf '%s\n' "$prefixes" | extract_stamps | expired_stamps "$cutoff")"; then
  fail_step retention
fi
if [[ -n "$expired" ]]; then
  while IFS= read -r old; do
    [[ -n "$old" ]] || continue
    is_backup_stamp "$old" || fail_step retention
    [[ "$old" == "$stamp" ]] && continue
    s3 s3 rm "s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${old}/" --recursive --only-show-errors || fail_step retention
  done <<<"$expired"
fi

marker_line "$stamp" > "$runtime_dir/backup-last-success"
chmod 600 "$runtime_dir/backup-last-success"
echo "backup_ok stamp=${stamp}"
