#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

db_name="schulit"

if ! mariadb --protocol=socket --batch --skip-column-names     -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${db_name}'"     | grep -qx "${db_name}"; then
  info "Anwendungsdatenbank existiert noch nicht; Migrationen werden bei der Ersteinrichtung angewendet."
  exit 0
fi

if ! mariadb --protocol=socket --batch --skip-column-names "${db_name}"     -e "SHOW TABLES LIKE 'schema_migrations'" | grep -qx "schema_migrations"; then
  warn "Datenbank existiert, aber schema_migrations fehlt. Automatische Migration wird aus Sicherheitsgründen übersprungen."
  exit 0
fi

for file in "${SCHULIT_SOURCE_ROOT}"/database/migrations/*.sql; do
  [[ -f "${file}" ]] || continue
  version="$(basename "${file}" .sql)"
  [[ "${version}" =~ ^[0-9]{3}_[A-Za-z0-9_-]+$ ]] || die "Ungültiger Migrationsname: ${version}"

  applied="$(mariadb --protocol=socket --batch --skip-column-names "${db_name}"       -e "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}'")"

  if [[ "${applied}" == "1" ]]; then
    info "Migration bereits angewendet: ${version}"
    continue
  fi

  info "Migration wird angewendet: ${version}"
  mariadb --protocol=socket "${db_name}" < "${file}"

  applied_after="$(mariadb --protocol=socket --batch --skip-column-names "${db_name}"       -e "SELECT COUNT(*) FROM schema_migrations WHERE version='${version}'")"
  [[ "${applied_after}" == "1" ]] || die "Migration ${version} hat sich nicht als angewendet registriert."
done

info "Anwendungsdatenbank ist auf dem aktuellen Schema-Stand."
