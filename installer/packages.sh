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
)

info "APT-Paketlisten werden aktualisiert ..."
apt-get update

info "Benötigte Serverpakete werden installiert ..."
apt-get install -y --no-install-recommends "${packages[@]}"

systemctl enable --now apache2
systemctl enable --now mariadb

info "Apache: $(apache2ctl -v | head -n 1)"
info "PHP: $(php -r 'echo PHP_VERSION;')"
info "MariaDB: $(mariadb --version | head -n 1)"
