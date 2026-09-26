#!/usr/bin/env bash
# Pure helpers for the single-VM backup and monitor jobs.
# Callers enable strict mode. This file does not run commands with side effects.

read_env_value() {
  local file="$1" key="$2" line value
  [[ -f "$file" ]] || return 1
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  [[ -n "$line" ]] || return 1
  value="${line#"${key}="}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then
    value="${value:1:$((${#value} - 2))}"
  elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
    value="${value:1:$((${#value} - 2))}"
  fi
  printf '%s' "$value"
}

is_backup_stamp() {
  [[ "$1" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]
}

is_sql_identifier() {
  [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_]{0,63}$ ]]
}

stamp_epoch() {
  local s="$1"
  is_backup_stamp "$s" || return 1
  date -u -d "${s:0:4}-${s:4:2}-${s:6:2} ${s:9:2}:${s:11:2}:${s:13:2} UTC" +%s
}

compose_project_name() {
  local file="$1" name
  if [[ -n "${COMPOSE_PROJECT_NAME:-}" ]]; then
    printf '%s' "$COMPOSE_PROJECT_NAME"
    return 0
  fi
  name="$(awk '/^name:[[:space:]]*/ { print $2; exit }' "$file")"
  if [[ -z "$name" ]]; then
    echo "compose_name_missing" >&2
    return 1
  fi
  printf '%s' "$name"
}

load_backup_config() {
  local file="$1" key value
  local -a required=(
    BACKUP_S3_ENDPOINT
    BACKUP_S3_BUCKET
    BACKUP_S3_ACCESS_KEY_ID
    BACKUP_S3_SECRET_ACCESS_KEY
  )
  if [[ ! -f "$file" ]]; then
    echo "backup_config_missing file" >&2
    return 1
  fi
  for key in "${required[@]}"; do
    value="$(read_env_value "$file" "$key" || true)"
    if [[ -z "$value" || "$value" == "runtime-only" || "$value" == "changeme" ]]; then
      echo "backup_config_missing ${key}" >&2
      return 1
    fi
    printf -v "$key" '%s' "$value"
    export "$key"
  done
  if [[ "$BACKUP_S3_ENDPOINT" != https://* ]]; then
    echo "backup_config_invalid BACKUP_S3_ENDPOINT" >&2
    return 1
  fi
  if [[ ! "$BACKUP_S3_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]]; then
    echo "backup_config_invalid BACKUP_S3_BUCKET" >&2
    return 1
  fi
  BACKUP_S3_REGION="$(read_env_value "$file" BACKUP_S3_REGION || true)"
  BACKUP_S3_REGION="${BACKUP_S3_REGION:-auto}"
  export BACKUP_S3_REGION
  BACKUP_S3_PREFIX="$(read_env_value "$file" BACKUP_S3_PREFIX || true)"
  BACKUP_S3_PREFIX="${BACKUP_S3_PREFIX:-business-omnichannel}"
  if [[ ! "$BACKUP_S3_PREFIX" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,127}$ ]]; then
    echo "backup_config_invalid BACKUP_S3_PREFIX" >&2
    return 1
  fi
  export BACKUP_S3_PREFIX
  BACKUP_RETENTION_DAYS="$(read_env_value "$file" BACKUP_RETENTION_DAYS || true)"
  BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
  if [[ ! "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || [[ "$BACKUP_RETENTION_DAYS" -lt 1 || "$BACKUP_RETENTION_DAYS" -gt 3650 ]]; then
    echo "backup_config_invalid BACKUP_RETENTION_DAYS" >&2
    return 1
  fi
  export BACKUP_RETENTION_DAYS
  BACKUP_INCLUDE_FILES="$(read_env_value "$file" BACKUP_INCLUDE_FILES || true)"
  BACKUP_INCLUDE_FILES="${BACKUP_INCLUDE_FILES:-true}"
  if [[ "$BACKUP_INCLUDE_FILES" != "true" && "$BACKUP_INCLUDE_FILES" != "false" ]]; then
    echo "backup_config_invalid BACKUP_INCLUDE_FILES" >&2
    return 1
  fi
  export BACKUP_INCLUDE_FILES
  ALERT_WEBHOOK_URL="$(read_env_value "$file" ALERT_WEBHOOK_URL || true)"
  if [[ -n "$ALERT_WEBHOOK_URL" && "$ALERT_WEBHOOK_URL" != https://* ]]; then
    echo "backup_config_invalid ALERT_WEBHOOK_URL" >&2
    return 1
  fi
  export ALERT_WEBHOOK_URL
  MONITOR_DISK_PERCENT="$(read_env_value "$file" MONITOR_DISK_PERCENT || true)"
  MONITOR_DISK_PERCENT="${MONITOR_DISK_PERCENT:-85}"
  if [[ ! "$MONITOR_DISK_PERCENT" =~ ^[0-9]+$ ]] || [[ "$MONITOR_DISK_PERCENT" -lt 50 || "$MONITOR_DISK_PERCENT" -gt 99 ]]; then
    echo "backup_config_invalid MONITOR_DISK_PERCENT" >&2
    return 1
  fi
  export MONITOR_DISK_PERCENT
  BACKUP_MAX_AGE_HOURS="$(read_env_value "$file" BACKUP_MAX_AGE_HOURS || true)"
  BACKUP_MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-30}"
  if [[ ! "$BACKUP_MAX_AGE_HOURS" =~ ^[0-9]+$ ]] || [[ "$BACKUP_MAX_AGE_HOURS" -lt 1 || "$BACKUP_MAX_AGE_HOURS" -gt 168 ]]; then
    echo "backup_config_invalid BACKUP_MAX_AGE_HOURS" >&2
    return 1
  fi
  export BACKUP_MAX_AGE_HOURS
  ALERT_REPEAT_HOURS="$(read_env_value "$file" ALERT_REPEAT_HOURS || true)"
  ALERT_REPEAT_HOURS="${ALERT_REPEAT_HOURS:-12}"
  if [[ ! "$ALERT_REPEAT_HOURS" =~ ^[0-9]+$ ]] || [[ "$ALERT_REPEAT_HOURS" -lt 1 || "$ALERT_REPEAT_HOURS" -gt 168 ]]; then
    echo "backup_config_invalid ALERT_REPEAT_HOURS" >&2
    return 1
  fi
  export ALERT_REPEAT_HOURS
}

extract_stamps() {
  local token base
  tr '\t' '\n' | while IFS= read -r token; do
    [[ -n "$token" ]] || continue
    base="${token%/}"
    base="${base##*/}"
    if is_backup_stamp "$base"; then
      printf '%s\n' "$base"
    fi
  done
}

expired_stamps() {
  local cutoff="$1" stamp epoch
  while IFS= read -r stamp; do
    [[ -n "$stamp" ]] || continue
    is_backup_stamp "$stamp" || continue
    epoch="$(stamp_epoch "$stamp")"
    if [[ "$epoch" -lt "$cutoff" ]]; then
      printf '%s\n' "$stamp"
    fi
  done
}

assess_stack() {
  local disk="$1" disk_limit="$2" backup_state="$3"
  local -a required=(
    caddy
    chatwoot-rails
    chatwoot-sidekiq
    chatwoot-postgres
    chatwoot-redis
    management-php
    management-nginx
    management-mysql
    ai
    ai-worker
  )
  local -a health_required=(chatwoot-postgres chatwoot-redis management-mysql ai)
  local -a reasons=()
  local name state health exitcode seen=" " item
  while IFS='|' read -r name state health exitcode; do
    [[ -n "$name" ]] || continue
    seen+="${name} "
    if [[ "$name" == "chatwoot-bootstrap" ]]; then
      if [[ "$state" == "running" || ( "$state" == "exited" && "$exitcode" == "0" ) ]]; then
        continue
      fi
      reasons+=(chatwoot-bootstrap)
      continue
    fi
    if [[ "$state" != "running" ]]; then
      reasons+=("$name")
      continue
    fi
    local expect_health=false
    for item in "${health_required[@]}"; do
      if [[ "$item" == "$name" ]]; then
        expect_health=true
      fi
    done
    if [[ "$expect_health" == true ]]; then
      if [[ "$health" != "healthy" && "$health" != "starting" ]]; then
        reasons+=("$name")
      fi
    elif [[ "$health" == "unhealthy" ]]; then
      reasons+=("$name")
    fi
  done
  for item in "${required[@]}"; do
    if [[ "$seen" != *" ${item} "* ]]; then
      reasons+=("$item")
    fi
  done
  if [[ "$disk" =~ ^[0-9]+$ ]] && [[ "$disk" -ge "$disk_limit" ]]; then
    reasons+=(disk)
  fi
  case "$backup_state" in
    fresh) ;;
    unconfigured) reasons+=(backup_unconfigured) ;;
    stale) reasons+=(backup_stale) ;;
    failed) reasons+=(backup_failed) ;;
    invalid) reasons+=(backup_invalid) ;;
    *) reasons+=(backup_unknown) ;;
  esac
  if [[ "${#reasons[@]}" -eq 0 ]]; then
    printf 'ok\n'
    return 0
  fi
  local joined
  joined="$(IFS=,; printf '%s' "${reasons[*]}")"
  printf 'unhealthy %s\n' "$joined"
}

backup_freshness() {
  local env_file="$1" success_file="$2" failure_file="$3" max_age_seconds="$4" now="$5"
  local fail_epoch=0 success_epoch=0 stamp
  if [[ ! -f "$env_file" ]]; then
    printf 'unconfigured'
    return 0
  fi
  if [[ -f "$failure_file" ]]; then
    stamp="$(awk 'NR==1 { print $1 }' "$failure_file")"
    fail_epoch="$(date -u -d "$stamp" +%s)"
  fi
  if [[ -f "$success_file" ]]; then
    stamp="$(awk 'NR==1 { print $1 }' "$success_file")"
    success_epoch="$(date -u -d "$stamp" +%s)"
  fi
  if [[ "$fail_epoch" -gt 0 && "$fail_epoch" -ge "$success_epoch" ]]; then
    printf 'failed'
    return 0
  fi
  if [[ "$success_epoch" -eq 0 ]]; then
    printf 'stale'
    return 0
  fi
  if [[ $((now - success_epoch)) -gt "$max_age_seconds" ]]; then
    printf 'stale'
    return 0
  fi
  printf 'fresh'
}

should_notify() {
  local last_sig="$1" last_epoch="$2" now="$3" sig="$4" repeat="$5"
  if [[ "$sig" == "ok" ]]; then
    if [[ -z "$last_sig" || "$last_sig" == "ok" ]]; then
      printf 'no'
    else
      printf 'yes'
    fi
    return 0
  fi
  if [[ "$sig" != "$last_sig" || -z "$last_epoch" ]]; then
    printf 'yes'
    return 0
  fi
  if [[ $((now - last_epoch)) -ge "$repeat" ]]; then
    printf 'yes'
  else
    printf 'no'
  fi
}

render_alert() {
  local status="$1" reasons="$2" host="$3"
  python3 -c '
import json, sys
status, reasons, host = sys.argv[1:]
items = [item for item in reasons.split(",") if item]
text = "business-omnichannel-ai " + status
if items:
    text += ": " + ", ".join(items)
if host:
    text += " (" + host + ")"
json.dump({"text": text, "content": text, "status": status, "reasons": items, "host": host}, sys.stdout)
' "$status" "$reasons" "$host"
}

marker_line() {
  local step="${1:-}"
  if [[ -n "$step" ]]; then
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$step"
  else
    printf '%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  fi
}
