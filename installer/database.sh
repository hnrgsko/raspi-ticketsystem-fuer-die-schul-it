#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

conf="/etc/mysql/mariadb.conf.d/99-schulit.cnf"

cat > "${conf}" <<'EOF'
# Managed by Schul-IT Ticketsystem installer.
# MariaDB remains local; the application will connect on this host only.
[mysqld]
bind-address = 127.0.0.1
local-infile = 0
skip-name-resolve
EOF

chmod 0644 "${conf}"

systemctl restart mariadb
systemctl enable mariadb >/dev/null

if ! mariadb --protocol=socket -e "SELECT 1;" >/dev/null 2>&1; then
  die "Lokaler MariaDB-Zugriff über Unix-Socket fehlgeschlagen."
fi

if command -v ss >/dev/null 2>&1; then
  listen="$(ss -ltnp 2>/dev/null | awk '$4 ~ /:3306$/ {print $4}' | paste -sd ',' -)"
  if [[ -n "${listen}" && "${listen}" != "127.0.0.1:3306" && "${listen}" != "[::1]:3306" ]]; then
    warn "MariaDB-Listenadresse prüfen: ${listen}"
  fi
fi

info "MariaDB läuft und ist für die spätere lokale Anwendungsdatenbank vorbereitet."
