#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

install -d -m 0755 /usr/local/lib/schulit
install -o root -g root -m 0755 "${SCHULIT_SOURCE_ROOT}/system/setupd.py" /usr/local/lib/schulit/setupd.py

install -d -m 0755 /opt/schulit/setup-migrations
rsync -a --delete "${SCHULIT_SOURCE_ROOT}/database/migrations/" /opt/schulit/setup-migrations/
chown -R root:root /opt/schulit/setup-migrations
find /opt/schulit/setup-migrations -type d -exec chmod 0755 {} +
find /opt/schulit/setup-migrations -type f -exec chmod 0644 {} +

cat > /etc/systemd/system/schulit-setupd.service <<'EOF'
[Unit]
Description=Schul-IT privileged setup service
After=mariadb.service
Requires=mariadb.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /usr/local/lib/schulit/setupd.py
Restart=on-failure
RestartSec=2
User=root
Group=root
UMask=0077
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now schulit-setupd.service

for _ in {1..20}; do
  [[ -S /run/schulit/setupd.sock ]] && break
  sleep 0.1
done

[[ -S /run/schulit/setupd.sock ]] || die "Setup-Systemdienst hat keinen Unix-Socket erstellt."
info "Privilegierter Setup-Systemdienst ist aktiv."
