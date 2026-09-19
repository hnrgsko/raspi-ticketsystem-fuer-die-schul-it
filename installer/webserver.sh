#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

cat > /etc/apache2/conf-available/schulit-setup-listen.conf <<'EOF'
Listen 8080
EOF

cat > /etc/apache2/sites-available/schulit-setup.conf <<'EOF'
<VirtualHost *:8080>
    ServerName schulit-setup.local
    DocumentRoot /opt/schulit/setup

    <Directory /opt/schulit/setup>
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
    Header always set Cache-Control "no-store"
    Header always set Content-Security-Policy "default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"

    ErrorLog ${APACHE_LOG_DIR}/schulit-setup-error.log
    CustomLog ${APACHE_LOG_DIR}/schulit-setup-access.log combined
</VirtualHost>
EOF

a2enmod headers >/dev/null
a2enconf schulit-setup-listen >/dev/null
a2ensite schulit-setup >/dev/null

apache2ctl configtest
systemctl reload apache2

info "Lokale Setup-Seite läuft auf Port 8080."
