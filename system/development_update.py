#!/usr/bin/env python3
"""Development-only updater for the Schul-IT test appliance.

This deliberately follows the mutable GitHub main branch and is therefore
never enabled for stable installations. It runs as its own systemd oneshot
service so the normal installer may safely restart schulit-setupd while the
update job continues.
"""
from __future__ import annotations

import datetime as dt
import grp
import json
import os
import pathlib
import subprocess
import tempfile
from typing import Any

STATUS_FILE = pathlib.Path("/var/lib/schulit/update-state/development.json")
LOG_FILE = pathlib.Path("/var/log/schulit/development-update.log")
INSTALLER_URL = (
    "https://raw.githubusercontent.com/"
    "hnrgsko/raspi-ticketsystem-fuer-die-schul-it/main/install.sh"
)
EXPECTED_REPO = 'PROJECT_REPO="hnrgsko/${PROJECT_SLUG}"'


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def write_status(payload: dict[str, Any]) -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATUS_FILE.with_name(STATUS_FILE.name + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.chmod(tmp, 0o640)
    os.chown(tmp, 0, grp.getgrnam("www-data").gr_gid)
    os.replace(tmp, STATUS_FILE)


def write_log(text: str) -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    LOG_FILE.write_text(text, encoding="utf-8", errors="replace")
    os.chmod(LOG_FILE, 0o640)
    os.chown(LOG_FILE, 0, grp.getgrnam("www-data").gr_gid)


def main() -> int:
    started_at = now_iso()
    write_status({
        "ok": True,
        "state": "running",
        "started_at": started_at,
        "finished_at": "",
        "message": "Entwicklerversion wird aus GitHub main aktualisiert.",
    })

    pathlib.Path("/var/cache/schulit").mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="schulit-dev-update-", dir="/var/cache/schulit") as tmp_dir:
        installer = pathlib.Path(tmp_dir) / "install.sh"

        download = subprocess.run(
            [
                "curl", "--fail", "--location", "--silent", "--show-error",
                "--proto", "=https", "--tlsv1.2",
                "--max-time", "60",
                "--output", str(installer),
                INSTALLER_URL,
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=75,
        )
        if download.returncode != 0:
            detail = (download.stderr or "").strip()[:500]
            write_log(detail + "\n")
            write_status({
                "ok": False,
                "state": "failed",
                "started_at": started_at,
                "finished_at": now_iso(),
                "message": "Installer konnte nicht von GitHub geladen werden.",
                "detail": detail,
            })
            return 1

        raw = installer.read_text(encoding="utf-8", errors="replace")
        if len(raw) < 2000 or EXPECTED_REPO not in raw or not raw.startswith("#!/usr/bin/env bash"):
            write_status({
                "ok": False,
                "state": "failed",
                "started_at": started_at,
                "finished_at": now_iso(),
                "message": "Geladener Entwicklungsinstaller hat ein unerwartetes Format.",
            })
            return 1

        env = os.environ.copy()
        env["SCHULIT_SOURCE_REF"] = "main"
        env["SCHULIT_DEV_UPDATE"] = "1"

        completed = subprocess.run(
            ["bash", str(installer)],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
            timeout=1200,
            env=env,
        )
        output = completed.stdout or ""
        write_log(output)

        if completed.returncode != 0:
            tail = "\n".join(output.splitlines()[-20:])[-3000:]
            write_status({
                "ok": False,
                "state": "failed",
                "started_at": started_at,
                "finished_at": now_iso(),
                "message": "Entwicklungsupdate ist bei der Installation fehlgeschlagen.",
                "detail": tail,
            })
            return completed.returncode or 1

    write_status({
        "ok": True,
        "state": "success",
        "started_at": started_at,
        "finished_at": now_iso(),
        "message": "Aktueller GitHub-main-Stand wurde installiert und geprüft.",
    })
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
