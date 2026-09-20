#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

install -d -m 0755 /usr/local/lib/schulit
install -o root -g root -m 0644 "${SCHULIT_SOURCE_ROOT}/system/update_core.py" /usr/local/lib/schulit/update_core.py
install -o root -g root -m 0755 "${SCHULIT_SOURCE_ROOT}/system/update_check.py" /usr/local/lib/schulit/update_check.py

install -d -o root -g root -m 0750 /var/lib/schulit/update-state

cat > /etc/systemd/system/schulit-update-check.service <<'EOF'
[Unit]
Description=Schul-IT nach neuen Releases suchen
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/schulit/update_check.py
User=root
Group=root
UMask=0027
PrivateTmp=true
ProtectHome=true
NoNewPrivileges=true
EOF

cat > /etc/systemd/system/schulit-update-check.timer <<'EOF'
[Unit]
Description=Schul-IT Releaseprüfung

[Timer]
OnBootSec=10min
OnUnitActiveSec=6h
RandomizedDelaySec=20min
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now schulit-update-check.timer >/dev/null

info "Update-Prüfdienst ist vorbereitet."
