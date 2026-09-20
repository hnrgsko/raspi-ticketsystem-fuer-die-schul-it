#!/usr/bin/env bash
set -u

section() { printf '\n===== %s =====\n' "$1"; }
service_state() {
  local unit="$1"
  if systemctl list-unit-files "${unit}" >/dev/null 2>&1; then
    printf '%s' "$(systemctl is-active "${unit}" 2>/dev/null || true)"
    printf ' / '
    printf '%s\n' "$(systemctl is-enabled "${unit}" 2>/dev/null || true)"
  else
    printf 'nicht installiert\n'
  fi
}
file_meta() {
  local path="$1"
  if [[ -e "${path}" ]]; then
    stat -c '%n -> %U:%G %a' "${path}"
  else
    printf '%s -> fehlt\n' "${path}"
  fi
}

section "Schul-IT Diagnose"
printf 'Zeit (UTC): %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf 'Hostname: %s\n' "$(hostname 2>/dev/null || echo unbekannt)"
printf 'Kernel: %s\n' "$(uname -srmo 2>/dev/null || echo unbekannt)"
if [[ -r /etc/os-release ]]; then
  . /etc/os-release
  printf 'Betriebssystem: %s\n' "${PRETTY_NAME:-unbekannt}"
fi

section "Versionen"
printf 'Installerstatus: '
if [[ -r /var/lib/schulit/setup/status.json ]]; then
  jq -r '"Version " + (.install_version // "?") + " · Phase " + ((.phase // "?")|tostring) + " · " + (.status // "?")' /var/lib/schulit/setup/status.json 2>/dev/null || echo "nicht lesbar"
else
  echo "nicht vorhanden"
fi
printf 'PHP: %s\n' "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unbekannt)"
printf 'MariaDB: %s\n' "$(mariadb --protocol=socket --batch --skip-column-names -e 'SELECT VERSION();' 2>/dev/null || echo unbekannt)"
printf 'Apache: %s\n' "$(apache2ctl -v 2>/dev/null | awk -F': ' '/Server version/ {print $2}' || true)"
printf 'Python: %s\n' "$(python3 --version 2>/dev/null || echo unbekannt)"
if [[ -x /usr/local/bin/cloudflared ]]; then
  printf 'cloudflared: %s\n' "$(/usr/local/bin/cloudflared --version 2>/dev/null || echo unbekannt)"
else
  printf 'cloudflared: nicht installiert\n'
fi

section "Dienste (aktiv / aktiviert)"
for unit in apache2.service mariadb.service schulit-setupd.service schulit-backup.timer schulit-update-check.timer schulit-tunnel.service; do
  printf '%-34s ' "${unit}:"
  service_state "${unit}"
done

section "Lokale Erreichbarkeit"
for target in "Setup|http://127.0.0.1:8080/" "Ticketsystem|http://127.0.0.1:8081/" "Admin|http://127.0.0.1:8081/admin/"; do
  label="${target%%|*}"
  url="${target#*|}"
  code="$(curl --silent --output /dev/null --max-time 5 --write-out '%{http_code}' "${url}" 2>/dev/null || true)"
  [[ -n "${code}" ]] || code="nicht erreichbar"
  printf '%-34s %s\n' "${label}:" "${code}"
done

section "Apache"
apache2ctl configtest 2>&1 || true

section "Datenbankschema"
if mariadb --protocol=socket --batch --skip-column-names schulit -e 'SELECT 1;' >/dev/null 2>&1; then
  printf 'Migrationen:\n'
  mariadb --protocol=socket --batch --skip-column-names schulit -e 'SELECT CONCAT("  ",version," · ",applied_at) FROM schema_migrations ORDER BY version;' 2>/dev/null || true
  printf '\nDatensatzanzahl (keine Inhalte):\n'
  for table in tickets faq_proposals faq_entries usage_daily admin_users; do
    if mariadb --protocol=socket --batch --skip-column-names schulit -e "SHOW TABLES LIKE '${table}'" 2>/dev/null | grep -qx "${table}"; then
      count="$(mariadb --protocol=socket --batch --skip-column-names schulit -e "SELECT COUNT(*) FROM \`${table}\`;" 2>/dev/null || echo '?')"
      printf '  %-24s %s\n' "${table}" "${count}"
    fi
  done
else
  printf 'Datenbank schulit nicht erreichbar.\n'
fi

section "Dateirechte"
for path in /etc/schulit /etc/schulit/app.php /etc/schulit/access-token /etc/schulit/tunnel.json /etc/schulit/cloudflared-token.env /var/lib/schulit/sessions /var/lib/schulit/admin-sessions /var/lib/schulit/uploads /var/lib/schulit/setup/installation.json /var/lib/schulit/recovery/recovery.json; do
  file_meta "${path}"
done

section "Speicher"
df -h / /var /opt 2>/dev/null | awk 'NR==1 || !seen[$6]++' || true

section "Hinweis"
cat <<'EOF'
Diese Diagnose gibt absichtlich keine Passwörter, Access-Tokens, Recovery-Codes,
Tunnel-Tokens, Tickettexte, Namen oder Apache-Zugriffslogs aus.
EOF
