#!/usr/bin/env bash
# One health line for the VM: containers, disk, and backup freshness.
# The webhook body is status metadata only. It never includes message text,
# database contents, or credential values.
set -euo pipefail
umask 077

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
# shellcheck source=lib.sh
source "$root/infra/ops/lib.sh"

runtime_dir="$root/runtime"
config_file="$runtime_dir/backup.env"
mkdir -p "$runtime_dir"

disk_limit=85
max_age_hours=30
repeat_hours=12
if [[ -f "$config_file" ]]; then
  if ! load_backup_config "$config_file"; then
    echo "monitor_config_invalid" >&2
    disk_limit=85
    max_age_hours=30
    repeat_hours=12
    ALERT_WEBHOOK_URL=""
    backup_state="invalid"
  else
    disk_limit="$MONITOR_DISK_PERCENT"
    max_age_hours="$BACKUP_MAX_AGE_HOURS"
    repeat_hours="$ALERT_REPEAT_HOURS"
    backup_state="$(backup_freshness "$config_file" "$runtime_dir/backup-last-success" "$runtime_dir/backup-last-failure" "$((max_age_hours * 3600))" "$(date -u +%s)")"
  fi
else
  ALERT_WEBHOOK_URL=""
  backup_state="$(backup_freshness "$config_file" "$runtime_dir/backup-last-success" "$runtime_dir/backup-last-failure" "$((max_age_hours * 3600))" "$(date -u +%s)")"
fi

disk="$(df -P / | awk 'NR==2 { gsub(/%/, "", $5); print $5 }')"

set +e
services="$(docker compose ps -a --format '{{.Service}}|{{.State}}|{{.Health}}|{{.ExitCode}}' 2>/dev/null)"
compose_rc=$?
set -e
if [[ "$compose_rc" -ne 0 ]]; then
  echo "monitor_failed step=compose" >&2
  reasons="docker"
  if [[ "$disk" =~ ^[0-9]+$ && "$disk" -ge "$disk_limit" ]]; then
    reasons+=",disk"
  fi
  if [[ "$backup_state" != "fresh" ]]; then
    reasons+=",backup_${backup_state}"
  fi
  assessment="unhealthy ${reasons}"
else
  assessment="$(printf '%s\n' "$services" | assess_stack "$disk" "$disk_limit" "$backup_state")"
fi

status="${assessment%% *}"
reasons=""
if [[ "$assessment" == *" "* ]]; then
  reasons="${assessment#* }"
fi
signature="$status"
if [[ -n "$reasons" ]]; then
  signature="${status}:${reasons}"
fi

host="$(hostname -s 2>/dev/null || hostname)"
payload="$(render_alert "$status" "$reasons" "$host")"
printf '%s\n' "$payload" > "$runtime_dir/monitor-status.json"
chmod 600 "$runtime_dir/monitor-status.json"
echo "monitor_status=${status} reasons=${reasons:-none} disk=${disk}"

state_file="$runtime_dir/monitor-alert-state"
last_sig=""
last_epoch=0
if [[ -f "$state_file" ]]; then
  last_sig="$(awk 'NR==1 { print $1 }' "$state_file")"
  last_epoch="$(awk 'NR==1 { print $2 }' "$state_file")"
  last_epoch="${last_epoch:-0}"
fi
now="$(date -u +%s)"
if [[ "$(should_notify "$last_sig" "$last_epoch" "$now" "$signature" "$((repeat_hours * 3600))")" == "yes" ]]; then
  if [[ -n "${ALERT_WEBHOOK_URL:-}" ]]; then
    if ! curl --fail --silent --max-time 10 \
      -H 'content-type: application/json' \
      --data-binary "$payload" \
      "$ALERT_WEBHOOK_URL" >/dev/null; then
      echo "alert_failed" >&2
      exit 1
    fi
    echo "alert_sent"
  else
    echo "alert_skipped reason=no_webhook"
  fi
  printf '%s %s\n' "$signature" "$now" > "$state_file"
  chmod 600 "$state_file"
fi

if [[ "$status" != "ok" ]]; then
  exit 1
fi
