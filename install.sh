#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_SLUG="raspi-ticketsystem-fuer-die-schul-it"
PROJECT_REPO="hnrgsko/${PROJECT_SLUG}"
SOURCE_REF="${SCHULIT_SOURCE_REF:-main}"
INSTALL_VERSION="0.5.0-dev"

log() { printf '\n[schulit] %s\n' "$*"; }
die() { printf '\n[schulit] FEHLER: %s\n' "$*" >&2; exit 1; }

if [[ "${EUID}" -ne 0 ]]; then
  die "Bitte als root starten, z. B. mit: sudo bash install.sh"
fi

SCRIPT_DIR=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
  SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
fi

bootstrap_source() {
  command -v curl >/dev/null 2>&1 || die "curl fehlt. Bitte zuerst installieren: sudo apt install curl"
  command -v tar >/dev/null 2>&1 || die "tar fehlt. Bitte zuerst installieren: sudo apt install tar"

  local tmp archive src
  tmp="$(mktemp -d)"
  archive="${tmp}/source.tar.gz"

  log "Installer-Dateien werden von GitHub geladen (Ref: ${SOURCE_REF}) ..."
  curl --fail --location --silent --show-error     "https://github.com/${PROJECT_REPO}/archive/refs/heads/${SOURCE_REF}.tar.gz"     --output "${archive}" || die "Installer konnte nicht heruntergeladen werden."

  tar -xzf "${archive}" -C "${tmp}"
  src="$(find "${tmp}" -mindepth 1 -maxdepth 1 -type d -name "${PROJECT_SLUG}-*" | head -n 1)"
  [[ -n "${src}" && -f "${src}/installer/common.sh" ]] || die "Heruntergeladenes Paket ist unvollständig."

  export SCHULIT_BOOTSTRAPPED=1
  exec bash "${src}/install.sh" "$@"
}

if [[ -z "${SCRIPT_DIR}" || ! -f "${SCRIPT_DIR}/installer/common.sh" ]]; then
  bootstrap_source "$@"
fi

export SCHULIT_SOURCE_ROOT="${SCRIPT_DIR}"
export SCHULIT_INSTALL_VERSION="${INSTALL_VERSION}"

# shellcheck source=installer/common.sh
source "${SCRIPT_DIR}/installer/common.sh"

acquire_install_lock

log "Schul-IT Ticketsystem – technische Basis und Setup-Assistent"
info "Quelle: ${PROJECT_REPO}@${SOURCE_REF}"
info "Installerversion: ${INSTALL_VERSION}"

run_step "System prüfen" "${SCRIPT_DIR}/installer/system-check.sh"
run_step "Pakete installieren" "${SCRIPT_DIR}/installer/packages.sh"
run_step "Dateisystem vorbereiten" "${SCRIPT_DIR}/installer/filesystem.sh"
run_step "MariaDB absichern" "${SCRIPT_DIR}/installer/database.sh"
run_step "Update-Prüfdienst einrichten" "${SCRIPT_DIR}/installer/update-service.sh"
run_step "Setup-Systemdienst einrichten" "${SCRIPT_DIR}/installer/setup-service.sh"
run_step "Backup-Dienst vorbereiten" "${SCRIPT_DIR}/installer/backup-service.sh"
run_step "Apache-Setupseite einrichten" "${SCRIPT_DIR}/installer/webserver.sh"
run_step "Anwendungsdatenbank aktualisieren" "${SCRIPT_DIR}/installer/app-migrations.sh"
run_step "Ticketsystem installieren" "${SCRIPT_DIR}/installer/application.sh"
run_step "Installation prüfen" "${SCRIPT_DIR}/installer/verify.sh"

if [[ "${SCHULIT_DEV_UPDATE:-0}" == "1" ]]; then
  printf '\n'
  printf '============================================================\n'
  printf ' Schul-IT Entwicklungsupdate abgeschlossen\n'
  printf '============================================================\n'
  printf ' Der aktuelle main-Stand wurde installiert und geprüft.\n'
  printf ' Aus Sicherheitsgründen werden in diesem Updateprotokoll\n'
  printf ' keine Setup- oder Kollegiums-Zugangstokens ausgegeben.\n'
  printf '============================================================\n'
else
  setup_token="$(cat /var/lib/schulit/setup/bootstrap-token 2>/dev/null || true)"
  access_token="$(cat /etc/schulit/access-token 2>/dev/null || true)"
  ip_address="$(hostname -I 2>/dev/null | awk '{print $1}')"
  hostname_value="$(hostname 2>/dev/null || echo raspberrypi)"
  
  printf '\n'
  printf '============================================================\n'
  printf ' Schul-IT Ticketsystem – Setup-Assistent bereit\n'
  printf '============================================================\n'
  printf ' Hostname: %s\n' "${hostname_value}"
  [[ -n "${ip_address}" ]] && printf ' Lokale IP: %s\n' "${ip_address}"
  printf '\n'
  printf ' Einrichtung im Browser öffnen:\n'
  if [[ -n "${ip_address}" && -n "${setup_token}" ]]; then
    printf ' http://%s:8080/?token=%s\n' "${ip_address}" "${setup_token}"
  elif [[ -n "${setup_token}" ]]; then
    printf ' http://%s.local:8080/?token=%s\n' "${hostname_value}" "${setup_token}"
  else
    printf ' http://%s.local:8080/\n' "${hostname_value}"
  fi
  printf '\n'
  printf ' Token später erneut anzeigen:\n'
  printf ' sudo cat /var/lib/schulit/setup/bootstrap-token\n'
  printf '\n'
  printf ' Im Browser folgen jetzt Schulname, Schulkennung, erster\n'
  printf ' System-Administrator und Recovery-Code.\n'
  printf '\n'
  printf ' Lokales Ticketsystem für das Kollegium:\n'
  if [[ -n "${ip_address}" && -n "${access_token}" ]]; then
    printf ' http://%s:8081/?access=%s\n' "${ip_address}" "${access_token}"
  elif [[ -n "${access_token}" ]]; then
    printf ' http://%s.local:8081/?access=%s\n' "${hostname_value}" "${access_token}"
  fi
  printf '\n'
  printf ' Ticket-Admin:\n'
  if [[ -n "${ip_address}" ]]; then
    printf ' http://%s:8081/admin/\n' "${ip_address}"
  else
    printf ' http://%s.local:8081/admin/\n' "${hostname_value}"
  fi
  printf '============================================================\n'
  
fi
