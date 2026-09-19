#!/usr/bin/env bash
set -Eeuo pipefail

SCHULIT_LOCK_FILE="/run/lock/schulit-install.lock"

log() { printf '\n[schulit] %s\n' "$*"; }
info() { printf '[schulit] %s\n' "$*"; }
warn() { printf '[schulit] WARNUNG: %s\n' "$*" >&2; }
die() { printf '[schulit] FEHLER: %s\n' "$*" >&2; exit 1; }

acquire_install_lock() {
  mkdir -p "$(dirname "${SCHULIT_LOCK_FILE}")"
  exec 9>"${SCHULIT_LOCK_FILE}"
  if ! flock -n 9; then
    die "Eine andere Schul-IT-Installation läuft bereits."
  fi
}

run_step() {
  local label="$1" script="$2"
  log "${label}"
  [[ -f "${script}" ]] || die "Installationsschritt fehlt: ${script}"
  bash "${script}"
}

require_root() {
  [[ "${EUID}" -eq 0 ]] || die "Dieser Schritt benötigt root-Rechte."
}

is_service_active() {
  systemctl is-active --quiet "$1"
}
