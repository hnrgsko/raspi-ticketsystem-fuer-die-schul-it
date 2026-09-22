#!/usr/bin/env bash
set -Eeuo pipefail

SCHULIT_LOCK_DIR="/run/lock/schulit-install.lock.d"
SCHULIT_LOCK_OWNED=0

log() { printf '\n[schulit] %s\n' "$*"; }
info() { printf '[schulit] %s\n' "$*"; }
warn() { printf '[schulit] WARNUNG: %s\n' "$*" >&2; }
die() { printf '[schulit] FEHLER: %s\n' "$*" >&2; exit 1; }

release_install_lock() {
  if [[ "${SCHULIT_LOCK_OWNED}" == "1" ]]; then
    rm -f "${SCHULIT_LOCK_DIR}/pid" 2>/dev/null || true
    rmdir "${SCHULIT_LOCK_DIR}" 2>/dev/null || true
    SCHULIT_LOCK_OWNED=0
  fi
}

acquire_install_lock() {
  mkdir -p /run/lock

  if mkdir "${SCHULIT_LOCK_DIR}" 2>/dev/null; then
    SCHULIT_LOCK_OWNED=1
    printf '%s\n' "$" > "${SCHULIT_LOCK_DIR}/pid"
    trap release_install_lock EXIT HUP INT TERM
    return
  fi

  local holder=""
  holder="$(cat "${SCHULIT_LOCK_DIR}/pid" 2>/dev/null || true)"
  if [[ "${holder}" =~ ^[0-9]+$ ]] && kill -0 "${holder}" 2>/dev/null; then
    die "Eine andere Schul-IT-Installation läuft bereits (PID ${holder})."
  fi

  # Stale lock from a previously aborted installer: remove it safely and retry.
  rm -f "${SCHULIT_LOCK_DIR}/pid" 2>/dev/null || true
  if ! rmdir "${SCHULIT_LOCK_DIR}" 2>/dev/null || ! mkdir "${SCHULIT_LOCK_DIR}" 2>/dev/null; then
    die "Installationssperre konnte nicht übernommen werden. Bitte erneut versuchen."
  fi

  SCHULIT_LOCK_OWNED=1
  printf '%s\n' "$" > "${SCHULIT_LOCK_DIR}/pid"
  trap release_install_lock EXIT HUP INT TERM
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
