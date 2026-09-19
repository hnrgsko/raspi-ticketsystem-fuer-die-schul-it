#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

cat > /etc/systemd/system/schulit-backup.service <<'EOF'
[Unit]
Description=Schul-IT encrypted backup
After=mariadb.service schulit-setupd.service
Requires=mariadb.service schulit-setupd.service
ConditionPathExists=/etc/schulit/backup-crypto.json

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/schulit/backup_client.py
User=root
Group=root
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
ProtectHome=true
EOF

cat > /etc/systemd/system/schulit-backup.timer <<'EOF'
[Unit]
Description=Daily Schul-IT backup

[Timer]
OnCalendar=*-*-* 03:30:00
RandomizedDelaySec=15m
Persistent=true
Unit=schulit-backup.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload

# Deliberately do not enable the timer here. It is enabled only after the
# recovery-code-protected backup encryption has been configured.
if systemctl is-enabled --quiet schulit-backup.timer 2>/dev/null; then
  info "Automatischer Backup-Timer ist bereits aktiviert."
else
  info "Backup-Timer vorbereitet; Aktivierung erfolgt nach Einrichtung der Verschlüsselung."
fi
