#!/usr/bin/env bash
# Install two host timers. Compose remains the stack definition; these timers
# are the only extra host jobs: a daily backup and a five-minute health check.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
backup_bin="$root/infra/ops/backup.sh"
monitor_bin="$root/infra/ops/monitor.sh"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "install_refused reason=root" >&2
  echo "Run: sudo $root/infra/ops/install-timers.sh" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "install_refused reason=systemd" >&2
  cat <<EOF
Add this crontab for the deploy user instead:

15 3 * * * $backup_bin >> $root/runtime/backup.log 2>&1
*/5 * * * * $monitor_bin >> $root/runtime/monitor.log 2>&1
EOF
  exit 1
fi

run_user="${SUDO_USER:-root}"
run_group="$(id -gn "$run_user")"
install -d -o "$run_user" -g "$run_group" -m 700 "$root/runtime"

write_unit() {
  local path="$1"
  cat > "$path"
  chmod 644 "$path"
}

write_unit /etc/systemd/system/omnichannel-backup.service <<EOF
[Unit]
Description=Business Omnichannel daily backup
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
WorkingDirectory=$root
ExecStart=$backup_bin
User=$run_user
Group=$run_group
TimeoutStartSec=3h
Nice=10

[Install]
WantedBy=multi-user.target
EOF

write_unit /etc/systemd/system/omnichannel-backup.timer <<EOF
[Unit]
Description=Daily Business Omnichannel backup

[Timer]
OnCalendar=*-*-* 03:15:00
Persistent=true
RandomizedDelaySec=15min

[Install]
WantedBy=timers.target
EOF

write_unit /etc/systemd/system/omnichannel-monitor.service <<EOF
[Unit]
Description=Business Omnichannel health check
After=docker.service

[Service]
Type=oneshot
WorkingDirectory=$root
ExecStart=$monitor_bin
User=$run_user
Group=$run_group
TimeoutStartSec=2min

[Install]
WantedBy=multi-user.target
EOF

write_unit /etc/systemd/system/omnichannel-monitor.timer <<EOF
[Unit]
Description=Business Omnichannel health check every five minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min
AccuracySec=30s
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now omnichannel-monitor.timer
systemctl enable --now omnichannel-backup.timer
echo "timers_installed user=${run_user}"
echo "backup_timer=omnichannel-backup.timer monitor_timer=omnichannel-monitor.timer"
if [[ ! -f "$root/runtime/backup.env" ]]; then
  echo "backup_env_missing copy=infra/ops/backup.example dest=runtime/backup.env" >&2
fi
