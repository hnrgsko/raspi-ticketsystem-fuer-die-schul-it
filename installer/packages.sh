#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

export DEBIAN_FRONTEND=noninteractive

packages=(
  apache2
  mariadb-server
  php
  php-cli
  libapache2-mod-php
  php-mysql
  php-mbstring
  php-xml
  php-curl
  php-zip
  php-intl
  php-gd
  php-opcache
  curl
  ca-certificates
  openssl
  jq
  rsync
  unzip
  iproute2
  util-linux
  avahi-daemon
)

info "APT-Paketlisten werden aktualisiert ..."
apt-get update

info "Benötigte Serverpakete werden installiert ..."
apt-get install -y --no-install-recommends "${packages[@]}"

# Apache is enabled here but started only after our own listener is configured.
# This prevents a conflict with another service already using port 80.
systemctl enable apache2 >/dev/null
systemctl enable --now mariadb
systemctl enable --now avahi-daemon

info "Apache: $(apache2ctl -v | head -n 1)"
info "PHP: $(php -r 'echo PHP_VERSION;')"
info "MariaDB: $(mariadb --version | head -n 1)"
info "mDNS/Avahi: aktiv"
