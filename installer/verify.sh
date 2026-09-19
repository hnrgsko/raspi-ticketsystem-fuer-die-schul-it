#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

failures=0

check() {
  local label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    printf '[schulit] ✓ %s\n' "${label}"
  else
    printf '[schulit] ✗ %s\n' "${label}" >&2
    failures=$((failures + 1))
  fi
}

check_setup_token() {
  local token cookiejar page
  token="$(cat /var/lib/schulit/setup/bootstrap-token)"
  cookiejar="$(mktemp)"
  page="$(mktemp)"

  if curl --fail --silent --show-error --location       --cookie-jar "${cookiejar}" --cookie "${cookiejar}"       --get --data-urlencode "token=${token}"       http://127.0.0.1:8080/ > "${page}"       && grep -q "Technische Details anzeigen" "${page}"       && ! grep -q 'name="token"' "${page}"; then
    printf '[schulit] ✓ Setup-Token wird akzeptiert\n'
  else
    printf '[schulit] ✗ Setup-Token wird nicht akzeptiert\n' >&2
    failures=$((failures + 1))
  fi

  rm -f "${cookiejar}" "${page}"
}

check "Apache-Konfiguration" apache2ctl configtest
check "Apache läuft" systemctl is-active apache2
check "MariaDB läuft" systemctl is-active mariadb
check "MariaDB lokal erreichbar" mariadb --protocol=socket -e "SELECT 1;"
check "Setup-Systemdienst läuft" systemctl is-active schulit-setupd.service
check "Setup-Systemsocket vorhanden" test -S /run/schulit/setupd.sock
check "PHP CLI verfügbar" php -v
check "PDO MySQL geladen" php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'
check "mbstring geladen" php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'
check "curl geladen" php -r 'exit(extension_loaded("curl") ? 0 : 1);'
check "Setup-Seite erreichbar" curl --fail --silent --show-error http://127.0.0.1:8080/
check_setup_token

if (( failures > 0 )); then
  die "${failures} Systemprüfung(en) fehlgeschlagen."
fi

php_version="$(php -r 'echo PHP_VERSION;')"
mariadb_version="$(mariadb --batch --skip-column-names -e 'SELECT VERSION();' 2>/dev/null || true)"
apache_version="$(apache2ctl -v | awk -F': ' '/Server version/ {print $2}')"

tmp="$(mktemp)"
jq -n   --arg install_version "${SCHULIT_INSTALL_VERSION:-0.2.0-dev}"   --arg php "${php_version}"   --arg mariadb "${mariadb_version}"   --arg apache "${apache_version}"   --arg verified_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"   '{
    phase: 2,
    install_version: $install_version,
    php: $php,
    mariadb: $mariadb,
    apache: $apache,
    verified_at: $verified_at,
    status: "ok"
  }' > "${tmp}"

install -o root -g www-data -m 0640 "${tmp}" /var/lib/schulit/setup/status.json
rm -f "${tmp}"

info "Alle Prüfungen erfolgreich."
