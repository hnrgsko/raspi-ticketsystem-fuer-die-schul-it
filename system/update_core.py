#!/usr/bin/env python3
from __future__ import annotations

import datetime as dt
import json
import os
import pathlib
import re
import subprocess
from typing import Any

SYSTEM_CONF = pathlib.Path("/etc/schulit/system.conf")
STATUS_FILE = pathlib.Path("/var/lib/schulit/update-state/status.json")
DEV_STATUS_FILE = pathlib.Path("/var/lib/schulit/update-state/development.json")
DEV_UNIT = "schulit-development-update.service"
RELEASE_API = "https://api.github.com/repos/hnrgsko/raspi-ticketsystem-fuer-die-schul-it/releases/latest"
SEMVER_RE = re.compile(
    r"\Av?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z.-]+))?\Z"
)


class UpdateError(Exception):
    pass


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def _read_system_conf() -> dict[str, str]:
    values: dict[str, str] = {}
    try:
        lines = SYSTEM_CONF.read_text(encoding="utf-8").splitlines()
    except OSError:
        return values
    for line in lines:
        if "=" not in line or line.lstrip().startswith("#"):
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if re.fullmatch(r"[A-Z0-9_]+", key):
            values[key] = value.strip()
    return values


def _semver(value: str) -> tuple[int, int, int, int, str] | None:
    match = SEMVER_RE.fullmatch(value.strip())
    if match is None:
        return None
    major, minor, patch = (int(match.group(i)) for i in (1, 2, 3))
    prerelease = match.group(4) or ""
    # Stable release sorts after a prerelease with the same numeric version.
    stable_rank = 1 if prerelease == "" else 0
    return major, minor, patch, stable_rank, prerelease


def is_newer(candidate: str, installed: str) -> bool:
    left = _semver(candidate)
    right = _semver(installed)
    if left is None or right is None:
        return False
    return left[:4] > right[:4] or (left[:4] == right[:4] and left[4] > right[4])


def _write_status(payload: dict[str, Any]) -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATUS_FILE.with_name(STATUS_FILE.name + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    tmp.chmod(0o640)
    # root:www-data
    import grp
    os.chown(tmp, 0, grp.getgrnam("www-data").gr_gid)
    tmp.replace(STATUS_FILE)


def _write_dev_status(payload: dict[str, Any]) -> None:
    DEV_STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = DEV_STATUS_FILE.with_name(DEV_STATUS_FILE.name + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    tmp.chmod(0o640)
    import grp
    os.chown(tmp, 0, grp.getgrnam("www-data").gr_gid)
    tmp.replace(DEV_STATUS_FILE)


def _unit_active(unit: str) -> bool:
    try:
        completed = subprocess.run(
            ["systemctl", "is-active", "--quiet", unit],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=10,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return False
    return completed.returncode == 0


def development_status() -> dict[str, Any]:
    conf = _read_system_conf()
    supported = conf.get("INSTALL_CHANNEL", "development") == "development"
    payload: dict[str, Any] = {
        "ok": True,
        "supported": supported,
        "state": "idle",
        "started_at": "",
        "finished_at": "",
        "message": "",
        "detail": "",
        "service_active": False,
    }
    if DEV_STATUS_FILE.is_file():
        try:
            loaded = json.loads(DEV_STATUS_FILE.read_text(encoding="utf-8"))
            if isinstance(loaded, dict):
                payload.update(loaded)
        except (OSError, json.JSONDecodeError):
            pass

    active = _unit_active(DEV_UNIT)
    payload["ok"] = True
    payload["supported"] = supported
    payload["service_active"] = active
    if active:
        payload["state"] = "running"
        if not payload.get("message"):
            payload["message"] = "Entwicklungsupdate läuft."
    return payload


def start_development_update() -> dict[str, Any]:
    conf = _read_system_conf()
    if conf.get("INSTALL_CHANNEL", "development") != "development":
        raise UpdateError("Direkte GitHub-main-Updates sind nur auf Entwicklungsinstanzen erlaubt.")
    if _unit_active(DEV_UNIT):
        return development_status()

    starting = {
        "ok": True,
        "supported": True,
        "state": "running",
        "started_at": now_iso(),
        "finished_at": "",
        "message": "Entwicklungsupdate wird gestartet.",
        "detail": "",
        "service_active": True,
    }
    _write_dev_status(starting)

    try:
        completed = subprocess.run(
            ["systemctl", "start", "--no-block", DEV_UNIT],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            check=False,
            timeout=15,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
        raise UpdateError("Entwicklungsupdate konnte nicht gestartet werden.") from exc
    if completed.returncode != 0:
        detail = (completed.stderr or "").strip()
        raise UpdateError(
            "Entwicklungsupdate konnte nicht gestartet werden"
            + (f": {detail}" if detail else ".")
        )
    return development_status()


def status() -> dict[str, Any]:
    conf = _read_system_conf()
    base: dict[str, Any] = {
        "ok": True,
        "installed_version": conf.get("INSTALL_VERSION", ""),
        "channel": conf.get("INSTALL_CHANNEL", "development"),
        "checked_at": "",
        "available": False,
        "latest_version": "",
        "release_url": "",
        "published_at": "",
        "release_notes": "",
        "error": "",
        "install_supported": False,
    }
    if not STATUS_FILE.is_file():
        return base
    try:
        loaded = json.loads(STATUS_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return base
    if isinstance(loaded, dict):
        base.update(loaded)
    base["ok"] = True
    base["install_supported"] = False
    return base


def check() -> dict[str, Any]:
    conf = _read_system_conf()
    installed = conf.get("INSTALL_VERSION", "")
    channel = conf.get("INSTALL_CHANNEL", "development")

    previous = status()
    payload: dict[str, Any] = {
        **previous,
        "ok": True,
        "installed_version": installed,
        "channel": channel,
        "checked_at": now_iso(),
        "install_supported": False,
        "error": "",
    }

    try:
        completed = subprocess.run(
            [
                "curl",
                "--fail",
                "--location",
                "--silent",
                "--show-error",
                "--max-time", "20",
                "--header", "Accept: application/vnd.github+json",
                "--header", "User-Agent: schulit-update-checker",
                RELEASE_API,
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=25,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
        payload["error"] = "Releasequelle konnte nicht erreicht werden."
        _write_status(payload)
        return payload

    if completed.returncode != 0:
        # A repository without a published release currently returns HTTP 404.
        detail = (completed.stderr or "").lower()
        if "404" in detail:
            payload.update({
                "available": False,
                "latest_version": "",
                "release_url": "",
                "published_at": "",
                "release_notes": "",
                "error": "",
            })
        else:
            payload["error"] = "Releasequelle konnte nicht abgefragt werden."
        _write_status(payload)
        return payload

    try:
        release = json.loads(completed.stdout)
    except json.JSONDecodeError:
        payload["error"] = "Releasequelle hat ungültige Metadaten geliefert."
        _write_status(payload)
        return payload

    if not isinstance(release, dict) or release.get("draft") is True or release.get("prerelease") is True:
        payload["error"] = "Release-Metadaten sind nicht als stabiles Release verwendbar."
        _write_status(payload)
        return payload

    tag = str(release.get("tag_name") or "")
    normalized = tag[1:] if tag.startswith("v") else tag
    if _semver(normalized) is None:
        payload["error"] = "Das veröffentlichte Release verwendet keine unterstützte SemVer-Version."
        _write_status(payload)
        return payload

    notes = str(release.get("body") or "")
    if len(notes) > 12000:
        notes = notes[:12000] + "\n…"

    payload.update({
        "latest_version": normalized,
        "available": is_newer(normalized, installed),
        "release_url": str(release.get("html_url") or ""),
        "published_at": str(release.get("published_at") or ""),
        "release_notes": notes,
        "error": "",
    })
    _write_status(payload)
    return payload
