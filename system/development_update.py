#!/usr/bin/env python3
"""Development-only updater for the Schul-IT test appliance.

The browser receives only a curated progress state. Raw installer output stays
root-only on the device and is never returned through the web UI.
"""
from __future__ import annotations

import datetime as dt
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

STEPS = [
    ("download", "Installer von GitHub laden", 5),
    ("source", "Projektdateien von GitHub laden", 10),
    ("system-check", "System prüfen", 18),
    ("packages", "Pakete prüfen / installieren", 26),
    ("filesystem", "Dateisystem vorbereiten", 34),
    ("database", "MariaDB absichern", 42),
    ("update-service", "Update-Dienst einrichten", 50),
    ("setup-service", "Systemdienst einrichten", 58),
    ("backup-service", "Backup-Dienst vorbereiten", 66),
    ("webserver", "Webserver einrichten", 74),
    ("migrations", "Datenbankmigrationen anwenden", 82),
    ("application", "Ticketsystem installieren", 90),
    ("verify", "Installation prüfen", 96),
]
STEP_BY_OUTPUT = {
    "Installer-Dateien werden von GitHub geladen": "source",
    "System prüfen": "system-check",
    "Pakete installieren": "packages",
    "Dateisystem vorbereiten": "filesystem",
    "MariaDB absichern": "database",
    "Update-Prüfdienst einrichten": "update-service",
    "Setup-Systemdienst einrichten": "setup-service",
    "Backup-Dienst vorbereiten": "backup-service",
    "Apache-Setupseite einrichten": "webserver",
    "Anwendungsdatenbank aktualisieren": "migrations",
    "Ticketsystem installieren": "application",
    "Installation prüfen": "verify",
}


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def step_payload(current_key: str, started_at: str, completed: list[str]) -> dict[str, Any]:
    current = next((item for item in STEPS if item[0] == current_key), STEPS[0])
    return {
        "ok": True,
        "state": "running",
        "started_at": started_at,
        "finished_at": "",
        "progress": current[2],
        "current_step": current[0],
        "current_label": current[1],
        "completed_steps": completed,
        "steps": [{"key": key, "label": label} for key, label, _ in STEPS],
        "message": current[1],
    }


def write_status(payload: dict[str, Any]) -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATUS_FILE.with_name(STATUS_FILE.name + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.chmod(tmp, 0o640)
    import grp
    os.chown(tmp, 0, grp.getgrnam("www-data").gr_gid)
    os.replace(tmp, STATUS_FILE)


def append_log(line: str) -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, "a", encoding="utf-8", errors="replace") as handle:
        handle.write(line)
    os.chmod(LOG_FILE, 0o600)
    os.chown(LOG_FILE, 0, 0)


def reset_log() -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    LOG_FILE.write_text("", encoding="utf-8")
    os.chmod(LOG_FILE, 0o600)
    os.chown(LOG_FILE, 0, 0)


def main() -> int:
    started_at = now_iso()
    completed_steps: list[str] = []
    current_key = "download"
    reset_log()
    write_status(step_payload(current_key, started_at, completed_steps))

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
            append_log((download.stderr or "") + "\n")
            write_status({
                **step_payload(current_key, started_at, completed_steps),
                "ok": False,
                "state": "failed",
                "finished_at": now_iso(),
                "message": "Installer konnte nicht von GitHub geladen werden.",
            })
            return 1

        completed_steps.append("download")
        current_key = "source"
        write_status(step_payload(current_key, started_at, completed_steps))

        raw = installer.read_text(encoding="utf-8", errors="replace")
        if len(raw) < 2000 or EXPECTED_REPO not in raw or not raw.startswith("#!/usr/bin/env bash"):
            write_status({
                **step_payload(current_key, started_at, completed_steps),
                "ok": False,
                "state": "failed",
                "finished_at": now_iso(),
                "message": "Geladener Entwicklungsinstaller hat ein unerwartetes Format.",
            })
            return 1

        env = os.environ.copy()
        env["SCHULIT_SOURCE_REF"] = "main"
        env["SCHULIT_DEV_UPDATE"] = "1"

        process = subprocess.Popen(
            ["bash", str(installer)],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            bufsize=1,
            env=env,
        )

        try:
            assert process.stdout is not None
            for line in process.stdout:
                append_log(line)
                clean = line.strip()
                if not clean.startswith("[schulit] "):
                    continue
                content = clean[len("[schulit] "):]
                for output_label, step_key in STEP_BY_OUTPUT.items():
                    if content == output_label or content.startswith(output_label + " "):
                        if current_key != step_key:
                            if current_key not in completed_steps:
                                completed_steps.append(current_key)
                            current_key = step_key
                            write_status(step_payload(current_key, started_at, completed_steps))
                        break
            returncode = process.wait(timeout=120)
        except Exception:
            process.kill()
            process.wait()
            write_status({
                **step_payload(current_key, started_at, completed_steps),
                "ok": False,
                "state": "failed",
                "finished_at": now_iso(),
                "message": "Entwicklungsupdate wurde unerwartet beendet.",
            })
            return 1

        if returncode != 0:
            write_status({
                **step_payload(current_key, started_at, completed_steps),
                "ok": False,
                "state": "failed",
                "finished_at": now_iso(),
                "message": f"Entwicklungsupdate ist beim Schritt „{step_payload(current_key, started_at, completed_steps)['current_label']}“ fehlgeschlagen.",
            })
            return returncode or 1

    if current_key not in completed_steps:
        completed_steps.append(current_key)
    write_status({
        "ok": True,
        "state": "success",
        "started_at": started_at,
        "finished_at": now_iso(),
        "progress": 100,
        "current_step": "done",
        "current_label": "Update abgeschlossen",
        "completed_steps": [key for key, _, _ in STEPS],
        "steps": [{"key": key, "label": label} for key, label, _ in STEPS],
        "message": "Aktueller GitHub-main-Stand wurde installiert und geprüft.",
    })
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
