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

check_file_meta() {
  local label="$1" path="$2" owner="$3" group="$4" mode="$5"
  local actual_owner actual_group actual_mode

  if [[ ! -e "${path}" ]]; then
    printf '[schulit] ✗ %s (fehlt)\n' "${label}" >&2
    failures=$((failures + 1))
    return
  fi

  actual_owner="$(stat -c '%U' "${path}")"
  actual_group="$(stat -c '%G' "${path}")"
  actual_mode="$(stat -c '%a' "${path}")"

  if [[ "${actual_owner}" == "${owner}" && "${actual_group}" == "${group}" && "${actual_mode}" == "${mode}" ]]; then
    printf '[schulit] ✓ %s\n' "${label}"
  else
    printf '[schulit] ✗ %s (ist %s:%s %s, erwartet %s:%s %s)\n'       "${label}" "${actual_owner}" "${actual_group}" "${actual_mode}"       "${owner}" "${group}" "${mode}" >&2
    failures=$((failures + 1))
  fi
}

check_latest_migration() {
  local latest
  latest="$(find "${SCHULIT_SOURCE_ROOT}/database/migrations" -maxdepth 1 -type f -name '*.sql' -printf '%f\n'     | sed 's/\.sql$//' | sort | tail -n 1)"

  [[ -n "${latest}" ]] || return 1

  mariadb --protocol=socket --batch --skip-column-names schulit     -e "SELECT COUNT(*) FROM schema_migrations WHERE version='${latest}'"     | grep -qx '1'
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

check_app_token() {
  local token cookiejar page
  token="$(cat /etc/schulit/access-token)"
  cookiejar="$(mktemp)"
  page="$(mktemp)"

  if ! curl --fail --silent --show-error --location       --cookie-jar "${cookiejar}" --cookie "${cookiejar}"       --get --data-urlencode "access=${token}"       http://127.0.0.1:8081/ > "${page}"; then
    printf '[schulit] ✗ Kollegiums-Zugangstoken wird nicht akzeptiert\n' >&2
    failures=$((failures + 1))
  elif grep -q "Zugang erforderlich" "${page}"; then
    printf '[schulit] ✗ Kollegiums-Zugangstoken wird nicht akzeptiert\n' >&2
    failures=$((failures + 1))
  elif [[ -f /var/lib/schulit/setup/installation.json ]]; then
    if grep -q "Wie können wir helfen" "${page}" && ! grep -q "Einrichtung noch nicht abgeschlossen" "${page}"; then
      printf '[schulit] ✓ Kollegiums-Zugang und Ticketdatenbank sind bereit\n'
    else
      printf '[schulit] ✗ Kollegiums-Zugang funktioniert, aber Ticketdatenbank ist nicht bereit\n' >&2
      failures=$((failures + 1))
    fi
  elif grep -q "Einrichtung noch nicht abgeschlossen" "${page}" || grep -q "Wie können wir helfen" "${page}"; then
    printf '[schulit] ✓ Kollegiums-Zugangstoken wird akzeptiert\n'
  else
    printf '[schulit] ✗ Kollegiumsseite liefert einen unerwarteten Zustand\n' >&2
    failures=$((failures + 1))
  fi

  rm -f "${cookiejar}" "${page}"
}

check_file_meta "/etc/schulit geschützt" /etc/schulit root www-data 710
check_file_meta "Kollegiums-Zugangstoken geschützt" /etc/schulit/access-token root www-data 640
check_file_meta "Öffentliche Sitzungen geschützt" /var/lib/schulit/sessions www-data www-data 700
check_file_meta "Admin-Sitzungen geschützt" /var/lib/schulit/admin-sessions www-data www-data 700
check_file_meta "Uploads für Webprozess beschreibbar" /var/lib/schulit/uploads www-data www-data 750

check "Apache-Konfiguration" apache2ctl configtest
check "Apache läuft" systemctl is-active apache2
check "MariaDB läuft" systemctl is-active mariadb
check "MariaDB lokal erreichbar" mariadb --protocol=socket -e "SELECT 1;"
check "Setup-Systemdienst läuft" systemctl is-active schulit-setupd.service
check "Setup-Systemsocket vorhanden" test -S /run/schulit/setupd.sock
check "USB-Erkennung antwortet" python3 -c 'import json,socket; s=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM); s.settimeout(3); s.connect("/run/schulit/setupd.sock"); s.sendall(b"{\"action\":\"list_backup_devices\"}\n"); data=s.recv(131072); r=json.loads(data.decode()); raise SystemExit(0 if r.get("ok") is True and isinstance(r.get("devices"), list) else 1)'
check "Restore-Suche antwortet" python3 -c 'import json,socket; s=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM); s.settimeout(5); s.connect("/run/schulit/setupd.sock"); s.sendall(b"{\"action\":\"discover_restore_backups\"}\n"); data=s.recv(131072); r=json.loads(data.decode()); raise SystemExit(0 if r.get("ok") is True and isinstance(r.get("backups"), list) else 1)'
check "age verfügbar" age --version
check "Python-Kryptografie verfügbar" python3 -c 'from cryptography.hazmat.primitives.ciphers.aead import AESGCM'
check "Backup-Timer installiert" systemctl cat schulit-backup.timer
check "Update-Prüftimer installiert" systemctl cat schulit-update-check.timer
check "Update-Prüftimer aktiviert" systemctl is-enabled schulit-update-check.timer
check "Update-Prüfmodul importierbar" python3 -c 'import sys; sys.path.insert(0,"/usr/local/lib/schulit"); import update_core'
check "PHP CLI verfügbar" php -v
check "PDO MySQL geladen" php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'
check "mbstring geladen" php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'
check "curl geladen" php -r 'exit(extension_loaded("curl") ? 0 : 1);'
check "Setup-Seite erreichbar" curl --fail --silent --show-error http://127.0.0.1:8080/
check "Ticketsystem auf Port 8081 erreichbar" curl --fail --silent --show-error http://127.0.0.1:8081/
check "Ticket-Admin erreichbar" curl --fail --silent --show-error http://127.0.0.1:8081/admin/

if [[ -f /etc/schulit/tunnel.json ]]; then
  check_file_meta "Tunnel-Konfiguration geschützt" /etc/schulit/tunnel.json root root 600
  check_file_meta "Tunnel-Token geschützt" /etc/schulit/cloudflared-token.env root root 600
  check_file_meta "cloudflared geschützt" /usr/local/bin/cloudflared root root 755
  check_file_meta "Tunnel-Systemdienst geschützt" /etc/systemd/system/schulit-tunnel.service root root 644
  check "Cloudflare Tunnel-Dienst läuft" systemctl is-active schulit-tunnel.service
fi
if [[ -f /var/lib/schulit/setup/installation.json ]]; then
  check_file_meta "Datenbankkonfiguration geschützt" /etc/schulit/app.php root www-data 640
  check_file_meta "Installationsstatus geschützt" /var/lib/schulit/setup/installation.json root www-data 640
  check_file_meta "Recovery-Prüfwert geschützt" /var/lib/schulit/recovery/recovery.json root root 600
  check "Anwendungsdatenbank über Web-Konfiguration erreichbar" runuser -u www-data -- php -r "require '/opt/schulit/application/lib.php'; \$db=app_database(); exit(app_tables_ready(\$db) ? 0 : 1);"
  check "Neueste Datenbankmigration angewendet" check_latest_migration
fi
check_setup_token
check_app_token

if (( failures > 0 )); then
  die "${failures} Systemprüfung(en) fehlgeschlagen."
fi

php_version="$(php -r 'echo PHP_VERSION;')"
mariadb_version="$(mariadb --batch --skip-column-names -e 'SELECT VERSION();' 2>/dev/null || true)"
apache_version="$(apache2ctl -v | awk -F': ' '/Server version/ {print $2}')"

tmp="$(mktemp)"
jq -n   --arg install_version "${SCHULIT_INSTALL_VERSION:-0.5.0-dev}"   --arg php "${php_version}"   --arg mariadb "${mariadb_version}"   --arg apache "${apache_version}"   --arg verified_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"   '{
    phase: 5,
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
