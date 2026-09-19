#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

setup_port=8080

if [[ -f /etc/apache2/ports.conf && ! -f /etc/apache2/ports.conf.schulit-preinstall ]]; then
  cp -a /etc/apache2/ports.conf /etc/apache2/ports.conf.schulit-preinstall
fi

cat > /etc/apache2/ports.conf <<'EOF'
# Managed by Schul-IT Ticketsystem.
# Public HTTP/HTTPS exposure is configured later by the setup assistant.
# The setup listener is defined in schulit-setup-listen.conf.
EOF

cat > /etc/apache2/conf-available/schulit-setup-listen.conf <<EOF
Listen ${setup_port}
EOF

cat > /etc/apache2/sites-available/schulit-setup.conf <<EOF
<VirtualHost *:${setup_port}>
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

    ErrorLog \${APACHE_LOG_DIR}/schulit-setup-error.log
    LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" schulit_setup
    CustomLog \${APACHE_LOG_DIR}/schulit-setup-access.log schulit_setup
</VirtualHost>
EOF

a2enmod headers >/dev/null
a2enconf schulit-setup-listen >/dev/null
a2ensite schulit-setup >/dev/null
a2dissite 000-default >/dev/null 2>&1 || true
a2dissite default-ssl >/dev/null 2>&1 || true

apache2ctl configtest
systemctl restart apache2
systemctl enable apache2 >/dev/null

info "Lokale Setup-Seite läuft auf Port ${setup_port}. Port 80/443 bleiben für andere Dienste frei."
