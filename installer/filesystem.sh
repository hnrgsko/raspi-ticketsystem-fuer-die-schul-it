#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

install -d -m 0755 /opt/schulit
install -d -m 0755 /opt/schulit/releases
install -d -m 0755 /opt/schulit/updater
install -d -m 0755 /opt/schulit/setup

install -d -m 0750 /etc/schulit
install -d -m 0750 /var/lib/schulit
install -d -m 0750 /var/lib/schulit/uploads
install -d -m 0750 /var/lib/schulit/sessions
install -d -m 0750 /var/lib/schulit/admin-sessions
install -d -m 0750 /var/lib/schulit/update-state
install -d -m 0750 /var/lib/schulit/migrations
install -d -m 0750 /var/lib/schulit/recovery
install -d -m 0750 /var/lib/schulit/setup
install -d -m 0750 /var/backups/schulit
install -d -m 0755 /var/log/schulit
install -d -m 0755 /var/cache/schulit
install -d -m 0755 /run/schulit

if [[ -d "${SCHULIT_SOURCE_ROOT}/setup" ]]; then
  rsync -a --delete "${SCHULIT_SOURCE_ROOT}/setup/" /opt/schulit/setup/
else
  die "Setup-Webanwendung fehlt."
fi

chown -R root:root /opt/schulit/setup
find /opt/schulit/setup -type d -exec chmod 0755 {} +
find /opt/schulit/setup -type f -exec chmod 0644 {} +

if [[ ! -s /var/lib/schulit/setup/bootstrap-token ]]; then
  token="$(openssl rand -hex 24)"
  printf '%s\n' "${token}" > /var/lib/schulit/setup/bootstrap-token
  chmod 0600 /var/lib/schulit/setup/bootstrap-token

  printf '%s' "${token}" | sha256sum | awk '{print $1}' > /var/lib/schulit/setup/token.sha256
fi

chown root:www-data /var/lib/schulit/setup/token.sha256
chmod 0640 /var/lib/schulit/setup/token.sha256

cat > /etc/schulit/system.conf <<EOF
INSTALL_VERSION=${SCHULIT_INSTALL_VERSION:-0.1.0-dev}
INSTALL_CHANNEL=development
SETUP_PORT=8080
EOF
chmod 0640 /etc/schulit/system.conf
chown root:root /etc/schulit/system.conf

info "FHS-nahe Verzeichnisstruktur wurde vorbereitet."
