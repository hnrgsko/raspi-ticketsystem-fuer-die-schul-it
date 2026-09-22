#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

install -d -m 0755 /usr/local/lib/schulit
install -o root -g root -m 0644 "${SCHULIT_SOURCE_ROOT}/system/update_core.py" /usr/local/lib/schulit/update_core.py
install -o root -g root -m 0755 "${SCHULIT_SOURCE_ROOT}/system/update_check.py" /usr/local/lib/schulit/update_check.py
install -o root -g root -m 0755 "${SCHULIT_SOURCE_ROOT}/system/development_update.py" /usr/local/lib/schulit/development_update.py

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

cat > /etc/systemd/system/schulit-development-update.service <<'EOF'
[Unit]
Description=Schul-IT development update from GitHub main
After=network-online.target
Wants=network-online.target
# Do not require MariaDB here: the installer intentionally restarts MariaDB
# during an update. A Requires= dependency would make systemd terminate this
# updater exactly while the database service is being restarted.

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/schulit/development_update.py
User=root
Group=root
UMask=0027
PrivateTmp=true
ProtectHome=true
NoNewPrivileges=true
# Development updates execute the full installer and may legitimately take
# several minutes on a Raspberry Pi, especially while apt is working.
TimeoutStartSec=35min
TimeoutStopSec=30s

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
