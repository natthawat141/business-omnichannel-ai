#!/usr/bin/env bash
# Host-free checks for backup selection, health decisions, and secret handling.
set -euo pipefail
umask 077

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=lib.sh
source "$root/infra/ops/lib.sh"

fail() {
  echo "FAIL $*" >&2
  exit 1
}

assert_eq() {
  local actual="$1" expected="$2" label="$3"
  if [[ "$actual" != "$expected" ]]; then
    fail "${label}: expected [${expected}] actual [${actual}]"
  fi
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

cat > "$tmp/backup.env" <<'EOF'
BACKUP_S3_ENDPOINT="https://example.r2.cloudflarestorage.com"
BACKUP_S3_BUCKET=business-backups
BACKUP_S3_REGION=auto
BACKUP_S3_ACCESS_KEY_ID=test-access-key
BACKUP_S3_SECRET_ACCESS_KEY=super-secret-value
BACKUP_S3_PREFIX=business-omnichannel
BACKUP_RETENTION_DAYS=14
BACKUP_INCLUDE_FILES=true
ALERT_WEBHOOK_URL=https://alerts.example/hook
MONITOR_DISK_PERCENT=85
BACKUP_MAX_AGE_HOURS=30
ALERT_REPEAT_HOURS=12
EOF

load_backup_config "$tmp/backup.env" 2>"$tmp/load.err" || fail "valid config rejected"
[[ ! -s "$tmp/load.err" ]] || fail "valid config wrote stderr"
assert_eq "$BACKUP_S3_ENDPOINT" "https://example.r2.cloudflarestorage.com" "endpoint unquoted"
[[ "$BACKUP_S3_SECRET_ACCESS_KEY" == "super-secret-value" ]] || fail "secret not loaded"
if grep -F -q "super-secret-value" "$tmp/load.err"; then
  fail "secret leaked to stderr"
fi

cat > "$tmp/bad.env" <<'EOF'
BACKUP_S3_ENDPOINT=http://example.r2.cloudflarestorage.com
BACKUP_S3_BUCKET=business-backups
BACKUP_S3_ACCESS_KEY_ID=test-access-key
BACKUP_S3_SECRET_ACCESS_KEY=super-secret-value
EOF
if load_backup_config "$tmp/bad.env" >"$tmp/bad.out" 2>"$tmp/bad.err"; then
  fail "http endpoint accepted"
fi
assert_eq "$(cat "$tmp/bad.err")" "backup_config_invalid BACKUP_S3_ENDPOINT" "http endpoint error"
if grep -F -q "super-secret-value" "$tmp/bad.err" "$tmp/bad.out"; then
  fail "secret leaked from invalid config"
fi

cat > "$tmp/placeholder.env" <<'EOF'
BACKUP_S3_ENDPOINT=https://example.r2.cloudflarestorage.com
BACKUP_S3_BUCKET=business-backups
BACKUP_S3_ACCESS_KEY_ID=runtime-only
BACKUP_S3_SECRET_ACCESS_KEY=changeme
EOF
if load_backup_config "$tmp/placeholder.env" >"$tmp/ph.out" 2>"$tmp/ph.err"; then
  fail "placeholder key accepted"
fi
assert_eq "$(cat "$tmp/ph.err")" "backup_config_missing BACKUP_S3_ACCESS_KEY_ID" "placeholder rejected"

printf '%s\n' 'name: sample-stack' > "$tmp/compose.yml"
assert_eq "$(compose_project_name "$tmp/compose.yml")" "sample-stack" "compose name"
COMPOSE_PROJECT_NAME=override-stack
assert_eq "$(compose_project_name "$tmp/compose.yml")" "override-stack" "compose override"
unset COMPOSE_PROJECT_NAME

is_backup_stamp "20260901T031500Z" || fail "stamp rejected"
if is_backup_stamp "../20260901T031500Z"; then
  fail "path stamp accepted"
fi
assert_eq "$(stamp_epoch "20260901T031500Z")" "$(date -u -d '2026-09-01 03:15:00 UTC' +%s)" "stamp epoch"

listing=$'business-omnichannel/20260901T031500Z/\tbusiness-omnichannel/20260920T031500Z/\nnotes\n../escape'
cutoff="$(date -u -d '2026-09-12 00:00:00 UTC' +%s)"
expired="$(printf '%s\n' "$listing" | extract_stamps | expired_stamps "$cutoff")"
assert_eq "$expired" "20260901T031500Z" "only old stamp expires"

services="$(cat <<'EOF'
caddy|running||0
chatwoot-rails|running||0
chatwoot-sidekiq|running||0
chatwoot-postgres|running|healthy|0
chatwoot-redis|running|healthy|0
management-php|running||0
management-nginx|running||0
management-mysql|running|healthy|0
ai|running|healthy|0
ai-worker|running||0
chatwoot-bootstrap|exited||0
EOF
)"
assert_eq "$(printf '%s\n' "$services" | assess_stack 20 85 fresh)" "ok" "healthy stack"

unhealthy="$(printf '%s\n' "$services" | assess_stack 90 85 stale)"
assert_eq "$unhealthy" "unhealthy disk,backup_stale" "disk and stale backup"
down="$(printf '%s\n' "$services" | sed 's/^ai|running|healthy/ai|running|unhealthy/')"
assert_eq "$(printf '%s\n' "$down" | assess_stack 10 85 fresh)" "unhealthy ai" "ai health"
missing="$(printf '%s\n' "$services" | grep -v '^ai-worker|')"
assert_eq "$(printf '%s\n' "$missing" | assess_stack 10 85 fresh)" "unhealthy ai-worker" "missing worker"
assert_eq "$(printf '%s\n' "$services" | assess_stack 10 85 invalid)" "unhealthy backup_invalid" "invalid backup config"

printf '%s\n' '2026-09-26T03:15:00Z 20260926T031500Z' > "$tmp/backup-last-success"
printf '%s\n' '2026-09-26T02:00:00Z dump-mysql' > "$tmp/backup-last-failure"
now="$(date -u -d '2026-09-26 04:00:00 UTC' +%s)"
assert_eq "$(backup_freshness "$tmp/backup.env" "$tmp/backup-last-success" "$tmp/backup-last-failure" 108000 "$now")" "fresh" "success newer than failure"
printf '%s\n' '2026-09-26T05:00:00Z upload' > "$tmp/backup-last-failure"
assert_eq "$(backup_freshness "$tmp/backup.env" "$tmp/backup-last-success" "$tmp/backup-last-failure" 108000 "$now")" "failed" "failure newer than success"
assert_eq "$(backup_freshness "$tmp/missing.env" "$tmp/backup-last-success" "$tmp/backup-last-failure" 108000 "$now")" "unconfigured" "missing env"

assert_eq "$(should_notify "" 0 1000 "ok" 3600)" "no" "first ok is quiet"
assert_eq "$(should_notify "ok" 1000 2000 "unhealthy:disk" 3600)" "yes" "new failure alerts"
assert_eq "$(should_notify "unhealthy:disk" 1000 2000 "unhealthy:disk" 3600)" "no" "repeat suppressed"
assert_eq "$(should_notify "unhealthy:disk" 1000 5000 "unhealthy:disk" 3600)" "yes" "repeat after window"
assert_eq "$(should_notify "unhealthy:disk" 1000 2000 "ok" 3600)" "yes" "recovery alerts"

payload="$(render_alert "unhealthy" "disk,backup_failed" "vm")"
if [[ "$payload" == *super-secret-value* || "$payload" == *test-access-key* ]]; then
  fail "alert payload leaked credentials"
fi
python3 -c '
import json, sys
body = json.loads(sys.argv[1])
assert body["status"] == "unhealthy"
assert body["reasons"] == ["disk", "backup_failed"]
assert body["text"] == body["content"]
assert "message" not in body
' "$payload"

if grep -E -n 'BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY|AKIA[0-9A-Z]{16}' "$root/infra/ops/backup.example"; then
  fail "example file contains key material"
fi

if "$root/infra/ops/restore.sh" nope >"$tmp/restore-stamp.out" 2>"$tmp/restore-stamp.err"; then
  fail "bad restore stamp accepted"
fi
assert_eq "$(cat "$tmp/restore-stamp.err")" "restore_refused reason=stamp" "restore stamp"
if "$root/infra/ops/restore.sh" 20260926T031500Z >"$tmp/restore-confirm.out" 2>"$tmp/restore-confirm.err"; then
  fail "restore without confirmation accepted"
fi
assert_eq "$(cat "$tmp/restore-confirm.err")" "restore_refused reason=confirm" "restore confirm"

bash -n "$root/infra/ops/backup.sh"
bash -n "$root/infra/ops/restore.sh"
bash -n "$root/infra/ops/monitor.sh"
bash -n "$root/infra/ops/install-timers.sh"
bash -n "$root/infra/ops/s3.sh"
echo "ops_checks_ok"
