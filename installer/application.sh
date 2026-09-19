#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

app_root="/opt/schulit/application"
access_token_file="/etc/schulit/access-token"

[[ -d "${SCHULIT_SOURCE_ROOT}/application" ]] || die "Anwendungsdateien fehlen."

# Early Phase-2 installations generated the local DB host as 127.0.0.1 while
# the dedicated MariaDB account is intentionally limited to @localhost.
# Normalize only our exact generated local configuration; custom DB hosts stay untouched.
if [[ -f /etc/schulit/app.php ]]    && grep -q "'user' => 'schulit_app'" /etc/schulit/app.php    && grep -q "'host' => '127.0.0.1'" /etc/schulit/app.php; then
  sed -i "s/'host' => '127\.0\.0\.1'/'host' => 'localhost'/" /etc/schulit/app.php
  chown root:www-data /etc/schulit/app.php
  chmod 0640 /etc/schulit/app.php
  info "Lokale Datenbankverbindung auf Unix-Socket/localhost aktualisiert."
fi

install -d -m 0755 "${app_root}"
rsync -a --delete "${SCHULIT_SOURCE_ROOT}/application/" "${app_root}/"
chown -R root:root "${app_root}"
find "${app_root}" -type d -exec chmod 0755 {} +
find "${app_root}" -type f -exec chmod 0644 {} +

# Only runtime data is writable by the web process.
install -d -o www-data -g www-data -m 0700 /var/lib/schulit/sessions
install -d -o www-data -g www-data -m 0700 /var/lib/schulit/admin-sessions
install -d -o www-data -g www-data -m 0750 /var/lib/schulit/uploads

if [[ ! -s "${access_token_file}" ]]; then
  openssl rand -hex 32 > "${access_token_file}"
fi
chown root:www-data "${access_token_file}"
chmod 0640 "${access_token_file}"

cat > /etc/apache2/conf-available/schulit-app-listen.conf <<'EOF'
Listen 8081
EOF

cat > /etc/apache2/sites-available/schulit-app.conf <<'EOF'
<VirtualHost *:8081>
    ServerName schulit-app.local
    DocumentRoot /opt/schulit/application
    DirectoryIndex index.php

    <Directory /opt/schulit/application>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>

    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always set Content-Security-Policy "default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"

    ErrorLog ${APACHE_LOG_DIR}/schulit-app-error.log

    # Query strings are intentionally excluded so access tokens never enter
    # the Apache access log.
    LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" schulit_app
    CustomLog ${APACHE_LOG_DIR}/schulit-app-access.log schulit_app
</VirtualHost>
EOF

a2enmod headers >/dev/null
a2enconf schulit-app-listen >/dev/null
a2ensite schulit-app >/dev/null

apache2ctl configtest
systemctl restart apache2

info "Lokales Ticketsystem läuft auf Port 8081."
