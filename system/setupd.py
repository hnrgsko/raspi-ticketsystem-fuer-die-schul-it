#!/usr/bin/env python3
"""Minimal privileged setup service for the Schul-IT appliance.

Only accepts local JSON requests over a Unix socket. It does not expose a TCP
port and it does not execute caller-supplied shell commands or URLs.
"""
from __future__ import annotations

import base64
import datetime as dt
import fcntl
import grp
import hashlib
import json
import os
import pathlib
import re
import secrets
import signal
import socket
import subprocess
import sys
from typing import Any

SOCKET_PATH = pathlib.Path("/run/schulit/setupd.sock")
STATE_DIR = pathlib.Path("/var/lib/schulit/setup")
STATE_FILE = STATE_DIR / "installation.json"
LOCK_FILE = STATE_DIR / "initialize.lock"
RECOVERY_DIR = pathlib.Path("/var/lib/schulit/recovery")
RECOVERY_FILE = RECOVERY_DIR / "recovery.json"
CONFIG_DIR = pathlib.Path("/etc/schulit")
APP_CONFIG = CONFIG_DIR / "app.php"
MIGRATION_DIR = pathlib.Path("/opt/schulit/setup-migrations")
DB_NAME = "schulit"
DB_USER = "schulit_app"

SCHOOL_ID_RE = re.compile(r"\A[A-Za-z0-9._-]{2,32}\Z")
USERNAME_RE = re.compile(r"\A[A-Za-z0-9._-]{3,100}\Z")


class SetupError(Exception):
    pass


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def atomic_write(path: pathlib.Path, data: str, mode: int, group: str | None = None) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp")
    with open(tmp, "w", encoding="utf-8") as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(tmp, mode)
    if group is not None:
        gid = grp.getgrnam(group).gr_gid
        os.chown(tmp, 0, gid)
    else:
        os.chown(tmp, 0, 0)
    os.replace(tmp, path)


def validate_text(value: Any, label: str, minimum: int, maximum: int) -> str:
    if not isinstance(value, str):
        raise SetupError(f"{label} fehlt.")
    value = value.strip()
    if not (minimum <= len(value) <= maximum):
        raise SetupError(f"{label}: {minimum}–{maximum} Zeichen erforderlich.")
    if any(ord(ch) < 32 or ord(ch) == 127 for ch in value):
        raise SetupError(f"{label} enthält ungültige Steuerzeichen.")
    return value


def hex_utf8(value: str) -> str:
    return "CONVERT(0x" + value.encode("utf-8").hex() + " USING utf8mb4)"


def run_mariadb(sql: str, database: str | None = None) -> str:
    command = ["mariadb", "--protocol=socket", "--batch", "--skip-column-names"]
    if database:
        command.append(database)
    try:
        completed = subprocess.run(
            command,
            input=sql,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=True,
            timeout=120,
        )
    except FileNotFoundError as exc:
        raise SetupError("MariaDB-Client wurde nicht gefunden.") from exc
    except subprocess.CalledProcessError as exc:
        detail = (exc.stderr or "").strip()
        raise SetupError("Datenbankaktion fehlgeschlagen" + (f": {detail}" if detail else ".")) from exc
    except subprocess.TimeoutExpired as exc:
        raise SetupError("Datenbankaktion hat zu lange gedauert.") from exc
    return completed.stdout.strip()


def apply_migrations() -> None:
    files = sorted(MIGRATION_DIR.glob("*.sql"))
    if not files:
        raise SetupError("Keine Datenbankmigrationen gefunden.")
    for path in files:
        sql = path.read_text(encoding="utf-8")
        run_mariadb(sql, DB_NAME)


def create_recovery_code(school_id: str) -> tuple[str, dict[str, Any]]:
    normalized = school_id.upper()
    secret = base64.b32encode(secrets.token_bytes(15)).decode("ascii").rstrip("=")
    grouped = "-".join(secret[i:i + 4] for i in range(0, len(secret), 4))
    code = f"{normalized}-{grouped}"

    salt = secrets.token_bytes(16)
    digest = hashlib.scrypt(
        code.encode("utf-8"),
        salt=salt,
        n=2**14,
        r=8,
        p=1,
        dklen=32,
    )
    record = {
        "scheme": "scrypt",
        "school_id": school_id,
        "salt_b64": base64.b64encode(salt).decode("ascii"),
        "hash_b64": base64.b64encode(digest).decode("ascii"),
        "created_at": now_iso(),
    }
    return code, record


def write_app_config(password: str) -> None:
    # password is generated with token_urlsafe and contains no single quotes.
    content = f"""<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => '{DB_NAME}',
        'user' => '{DB_USER}',
        'password' => '{password}',
        'charset' => 'utf8mb4',
    ],
];
"""
    atomic_write(APP_CONFIG, content, 0o640, "www-data")


def initialize(payload: dict[str, Any]) -> dict[str, Any]:
    school_name = validate_text(payload.get("school_name"), "Schulname", 2, 150)
    school_id = validate_text(payload.get("school_id"), "Schulnummer / Schulkennung", 2, 32)
    if SCHOOL_ID_RE.fullmatch(school_id) is None:
        raise SetupError("Schulnummer / Schulkennung darf nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich enthalten.")

    display_name = validate_text(payload.get("admin_display_name"), "Anzeigename", 1, 100)
    username = validate_text(payload.get("admin_username"), "Benutzername", 3, 100)
    if USERNAME_RE.fullmatch(username) is None:
        raise SetupError("Benutzername: 3–100 Buchstaben, Ziffern, Punkte, Unterstriche oder Bindestriche.")

    password_hash = payload.get("admin_password_hash")
    if not isinstance(password_hash, str) or not (20 <= len(password_hash) <= 255) or "\x00" in password_hash:
        raise SetupError("Ungültiger Passwort-Hash.")

    STATE_DIR.mkdir(parents=True, exist_ok=True)
    with open(LOCK_FILE, "a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        if STATE_FILE.exists():
            raise SetupError("Die Ersteinrichtung wurde bereits abgeschlossen.")

        db_password = secrets.token_urlsafe(32)
        root_sql = f"""
CREATE DATABASE IF NOT EXISTS `{DB_NAME}`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '{DB_USER}'@'localhost' IDENTIFIED BY '{db_password}';
ALTER USER '{DB_USER}'@'localhost' IDENTIFIED BY '{db_password}';
GRANT SELECT, INSERT, UPDATE, DELETE ON `{DB_NAME}`.* TO '{DB_USER}'@'localhost';
FLUSH PRIVILEGES;
"""
        run_mariadb(root_sql)
        apply_migrations()

        existing = run_mariadb("SELECT COUNT(*) FROM admin_users;", DB_NAME)
        if existing not in ("", "0"):
            raise SetupError("In der Datenbank existiert bereits ein Administratorkonto.")

        settings_sql = f"""
START TRANSACTION;
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('school_name', {hex_utf8(school_name)})
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=CURRENT_TIMESTAMP(6);
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('school_id', {hex_utf8(school_id)})
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=CURRENT_TIMESTAMP(6);
INSERT INTO admin_users
  (username, display_name, password_hash, role, is_active, must_change_password)
VALUES
  ({hex_utf8(username)}, {hex_utf8(display_name)}, {hex_utf8(password_hash)}, 'system_admin', 1, 0);
COMMIT;
"""
        run_mariadb(settings_sql, DB_NAME)

        recovery_code, recovery_record = create_recovery_code(school_id)
        RECOVERY_DIR.mkdir(parents=True, exist_ok=True)
        atomic_write(RECOVERY_FILE, json.dumps(recovery_record, ensure_ascii=False, indent=2) + "\n", 0o600)
        write_app_config(db_password)

        state = {
            "initialized": True,
            "school_name": school_name,
            "school_id": school_id,
            "admin_username": username,
            "admin_display_name": display_name,
            "role": "system_admin",
            "database": DB_NAME,
            "initialized_at": now_iso(),
        }
        atomic_write(STATE_FILE, json.dumps(state, ensure_ascii=False, indent=2) + "\n", 0o640, "www-data")

        return {
            "ok": True,
            "state": state,
            "recovery_code": recovery_code,
        }


def get_status() -> dict[str, Any]:
    if not STATE_FILE.exists():
        return {"ok": True, "initialized": False}
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        raise SetupError("Installationsstatus ist beschädigt.")
    return {"ok": True, "initialized": True, "state": state}


def handle(request: dict[str, Any]) -> dict[str, Any]:
    action = request.get("action")
    if action == "status":
        return get_status()
    if action == "initialize":
        payload = request.get("payload")
        if not isinstance(payload, dict):
            raise SetupError("Ungültige Einrichtungsdaten.")
        return initialize(payload)
    raise SetupError("Unbekannte Setup-Aktion.")


def serve() -> None:
    SOCKET_PATH.parent.mkdir(parents=True, exist_ok=True)
    if SOCKET_PATH.exists() or SOCKET_PATH.is_socket():
        SOCKET_PATH.unlink()

    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(str(SOCKET_PATH))
    os.chmod(SOCKET_PATH, 0o660)
    os.chown(SOCKET_PATH, 0, grp.getgrnam("www-data").gr_gid)
    server.listen(8)

    def stop(_signum: int, _frame: Any) -> None:
        server.close()
        try:
            SOCKET_PATH.unlink()
        except FileNotFoundError:
            pass
        raise SystemExit(0)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    while True:
        try:
            conn, _ = server.accept()
        except OSError:
            break
        with conn:
            raw = b""
            while not raw.endswith(b"\n") and len(raw) <= 65536:
                chunk = conn.recv(4096)
                if not chunk:
                    break
                raw += chunk
            try:
                if len(raw) > 65536:
                    raise SetupError("Anfrage ist zu groß.")
                request = json.loads(raw.decode("utf-8"))
                if not isinstance(request, dict):
                    raise SetupError("Ungültige Anfrage.")
                response = handle(request)
            except SetupError as exc:
                response = {"ok": False, "error": str(exc)}
            except Exception:
                response = {"ok": False, "error": "Interner Setup-Fehler."}
            conn.sendall((json.dumps(response, ensure_ascii=False) + "\n").encode("utf-8"))


if __name__ == "__main__":
    try:
        serve()
    except Exception as exc:
        print(f"schulit-setupd: {exc}", file=sys.stderr)
        raise
