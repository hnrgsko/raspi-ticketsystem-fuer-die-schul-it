#!/usr/bin/env bash
set -Eeuo pipefail
source "${SCHULIT_SOURCE_ROOT}/installer/common.sh"
require_root

[[ -r /etc/os-release ]] || die "/etc/os-release fehlt."
# shellcheck disable=SC1091
source /etc/os-release

case "${ID:-}" in
  raspbian|debian) ;;
  *)
    if [[ "${ID_LIKE:-}" != *debian* && "${SCHULIT_ALLOW_UNSUPPORTED:-0}" != "1" ]]; then
      die "Unterstützt wird Raspberry Pi OS/Debian. Erkannt: ${PRETTY_NAME:-unbekannt}"
    fi
    warn "Nicht standardmäßig getestetes System: ${PRETTY_NAME:-unbekannt}"
    ;;
esac

arch="$(dpkg --print-architecture 2>/dev/null || uname -m)"
case "${arch}" in
  arm64|aarch64)
    info "Architektur: ${arch}"
    ;;
  *)
    if [[ "${SCHULIT_ALLOW_UNSUPPORTED:-0}" != "1" ]]; then
      die "Für v1 wird Raspberry Pi OS 64-bit (arm64) erwartet. Erkannt: ${arch}"
    fi
    warn "Nicht unterstützte Architektur: ${arch}"
    ;;
esac

model="unbekannt"
if [[ -r /proc/device-tree/model ]]; then
  model="$(tr -d '\0' < /proc/device-tree/model)"
fi
info "Gerät: ${model}"

if [[ "${model}" != *"Raspberry Pi"* && "${SCHULIT_ALLOW_UNSUPPORTED:-0}" != "1" ]]; then
  die "Kein Raspberry Pi erkannt. Für Entwicklung SCHULIT_ALLOW_UNSUPPORTED=1 verwenden."
fi

free_kb="$(df -Pk / | awk 'NR==2 {print $4}')"
min_kb=$((4 * 1024 * 1024))
if (( free_kb < min_kb )); then
  die "Mindestens 4 GiB freier Speicher auf / erforderlich."
fi
info "Freier Speicher auf /: $((free_kb / 1024)) MiB"

mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"
if (( mem_kb < 700000 )); then
  warn "Weniger als ca. 700 MiB RAM erkannt. Der Betrieb kann eingeschränkt sein."
else
  info "RAM: $((mem_kb / 1024)) MiB"
fi

if command -v systemctl >/dev/null 2>&1; then
  [[ "$(ps -p 1 -o comm=)" == "systemd" ]] || die "systemd wird als Init-System erwartet."
else
  die "systemctl fehlt."
fi

info "Systemprüfung erfolgreich: ${PRETTY_NAME:-Debian}, Codename ${VERSION_CODENAME:-unbekannt}."
