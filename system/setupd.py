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
import traceback
from typing import Any

import backup_core

SOCKET_PATH = pathlib.Path("/run/schulit/setupd.sock")
STATE_DIR = pathlib.Path("/var/lib/schulit/setup")
STATE_FILE = STATE_DIR / "installation.json"
LOCK_FILE = STATE_DIR / "initialize.lock"
RECOVERY_DIR = pathlib.Path("/var/lib/schulit/recovery")
RECOVERY_FILE = RECOVERY_DIR / "recovery.json"
CONFIG_DIR = pathlib.Path("/etc/schulit")
APP_CONFIG = CONFIG_DIR / "app.php"
BACKUP_CONFIG = CONFIG_DIR / "backup.json"
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
        'host' => 'localhost',
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


def _run_json_command(command: list[str]) -> dict[str, Any]:
    try:
        completed = subprocess.run(
            command,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=True,
            timeout=20,
        )
    except (FileNotFoundError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        raise SetupError("USB-Datenträger konnten nicht zuverlässig erkannt werden.") from exc
    try:
        decoded = json.loads(completed.stdout)
    except json.JSONDecodeError as exc:
        raise SetupError("Ungültige Antwort der Datenträgererkennung.") from exc
    if not isinstance(decoded, dict):
        raise SetupError("Ungültige Antwort der Datenträgererkennung.")
    return decoded


def _first_mountpoint(node: dict[str, Any]) -> str | None:
    raw = node.get("mountpoints")
    if isinstance(raw, list):
        for value in raw:
            if isinstance(value, str) and value:
                return value
    value = node.get("mountpoint")
    return value if isinstance(value, str) and value else None


def list_usb_devices() -> list[dict[str, Any]]:
    tree = _run_json_command([
        "lsblk", "--json", "--bytes", "--paths",
        "-o", "NAME,PATH,TYPE,SIZE,FSTYPE,LABEL,UUID,MOUNTPOINTS,RM,RO,TRAN,MODEL,VENDOR"
    ])
    supported = {"ext4", "ext3", "ext2", "exfat", "vfat", "ntfs", "ntfs3"}
    devices: list[dict[str, Any]] = []

    def walk(node: dict[str, Any], parent_usb: bool = False, parent_model: str = "") -> None:
        transport = str(node.get("tran") or "").lower()
        is_usb = parent_usb or transport == "usb"
        model = str(node.get("model") or parent_model or "").strip()
        node_type = str(node.get("type") or "")
        fstype = str(node.get("fstype") or "").lower()
        uuid = str(node.get("uuid") or "").strip()
        path = str(node.get("path") or node.get("name") or "")

        if is_usb and node_type in {"part", "disk"} and fstype and uuid and path:
            size = node.get("size")
            try:
                size_bytes = int(size)
            except (TypeError, ValueError):
                size_bytes = 0
            read_only = bool(node.get("ro"))
            devices.append({
                "uuid": uuid,
                "path": path,
                "label": str(node.get("label") or "").strip(),
                "model": model,
                "vendor": str(node.get("vendor") or "").strip(),
                "size_bytes": size_bytes,
                "fstype": fstype,
                "mountpoint": _first_mountpoint(node),
                "read_only": read_only,
                "supported": (fstype in supported and not read_only),
            })

        children = node.get("children")
        if isinstance(children, list):
            for child in children:
                if isinstance(child, dict):
                    walk(child, is_usb, model)

    blockdevices = tree.get("blockdevices")
    if isinstance(blockdevices, list):
        for entry in blockdevices:
            if isinstance(entry, dict):
                walk(entry)

    devices.sort(key=lambda item: (item["label"] or item["model"], item["path"]))
    return devices


def _load_installation_state() -> dict[str, Any]:
    if not STATE_FILE.exists():
        raise SetupError("Bitte zuerst die Grundkonfiguration abschließen.")
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SetupError("Installationsstatus ist beschädigt.") from exc
    if not isinstance(state, dict):
        raise SetupError("Installationsstatus ist beschädigt.")
    return state


def _backup_device_by_uuid(uuid: str) -> dict[str, Any]:
    if not isinstance(uuid, str) or len(uuid) > 128:
        raise SetupError("Ungültige Datenträger-ID.")
    for device in list_usb_devices():
        if secrets.compare_digest(str(device["uuid"]), uuid):
            return device
    raise SetupError("Der ausgewählte USB-Datenträger wurde nicht gefunden.")


def _safe_dir(parent: pathlib.Path, name: str) -> pathlib.Path:
    target = parent / name
    if target.is_symlink():
        raise SetupError("Der vorgesehene Backup-Pfad enthält einen symbolischen Link und wird aus Sicherheitsgründen nicht verwendet.")
    if target.exists() and not target.is_dir():
        raise SetupError("Der vorgesehene Backup-Pfad wird bereits von einer Datei belegt.")
    target.mkdir(mode=0o755, exist_ok=True)
    return target


def _write_json_exclusive(path: pathlib.Path, payload: dict[str, Any]) -> None:
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    fd = os.open(path, flags, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
    except Exception:
        try:
            path.unlink()
        except FileNotFoundError:
            pass
        raise


def _prepare_backup_folder(mountpoint: pathlib.Path, device: dict[str, Any], school_id: str) -> str:
    root = mountpoint / "SchulIT-Ticketsystem"
    root_marker = root / ".schulit-root.json"

    if root.is_symlink():
        raise SetupError("Der Ordner SchulIT-Ticketsystem ist ein symbolischer Link und wird nicht verwendet.")
    if root.exists() and not root.is_dir():
        raise SetupError("Auf dem USB-Stick existiert bereits eine Datei namens SchulIT-Ticketsystem.")

    if root.exists() and not root_marker.exists():
        try:
            has_content = any(root.iterdir())
        except OSError as exc:
            raise SetupError("Der vorhandene Ordner SchulIT-Ticketsystem kann nicht geprüft werden.") from exc
        if has_content:
            raise SetupError(
                "Auf dem USB-Stick gibt es bereits einen nicht von diesem System verwalteten Ordner "
                "SchulIT-Ticketsystem. Aus Sicherheitsgründen wird darin nichts verändert."
            )

    if not root.exists():
        root.mkdir(mode=0o755)

    if not root_marker.exists():
        _write_json_exclusive(root_marker, {
            "format": "schulit-backup-root-v1",
            "created_at": now_iso(),
        })
    else:
        try:
            marker = json.loads(root_marker.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise SetupError("Der vorhandene SchulIT-Ticketsystem-Ordner hat keinen gültigen Verwaltungsmarker.") from exc
        if not isinstance(marker, dict) or marker.get("format") != "schulit-backup-root-v1":
            raise SetupError("Der vorhandene SchulIT-Ticketsystem-Ordner gehört nicht zu einem unterstützten Backupformat.")

    backups = _safe_dir(root, "Backups")
    school = backups / school_id
    school_marker = school / ".schulit-school.json"

    if school.is_symlink():
        raise SetupError("Der schulbezogene Backup-Pfad ist ein symbolischer Link und wird nicht verwendet.")
    if school.exists() and not school.is_dir():
        raise SetupError("Der schulbezogene Backup-Pfad wird bereits von einer Datei belegt.")

    if school.exists() and not school_marker.exists():
        try:
            has_content = any(school.iterdir())
        except OSError as exc:
            raise SetupError("Der vorhandene Schul-Backupordner kann nicht geprüft werden.") from exc
        if has_content:
            raise SetupError(
                "Für diese Schulkennung existiert bereits ein nicht verwalteter Ordner. "
                "Es werden keine vorhandenen Inhalte überschrieben."
            )

    if not school.exists():
        school.mkdir(mode=0o755)

    if not school_marker.exists():
        _write_json_exclusive(school_marker, {
            "format": "schulit-school-backup-v1",
            "school_id": school_id,
            "device_uuid": device["uuid"],
            "created_at": now_iso(),
        })
    else:
        try:
            marker = json.loads(school_marker.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise SetupError("Der vorhandene Schul-Backupordner hat keinen gültigen Marker.") from exc
        if not isinstance(marker, dict) or marker.get("school_id") != school_id:
            raise SetupError("Der vorhandene Backupordner gehört zu einer anderen Schulinstallation.")

    _safe_dir(school, "archives")
    _safe_dir(school, "manifests")
    return f"SchulIT-Ticketsystem/Backups/{school_id}"


def register_backup_device(uuid: str) -> dict[str, Any]:
    state = _load_installation_state()
    school_id = str(state.get("school_id") or "")
    if SCHOOL_ID_RE.fullmatch(school_id) is None:
        raise SetupError("Die gespeicherte Schulkennung ist ungültig.")

    device = _backup_device_by_uuid(uuid)
    if not bool(device.get("supported")):
        raise SetupError("Dieser Datenträger ist schreibgeschützt oder verwendet ein noch nicht unterstütztes Dateisystem.")

    mounted_here = False
    temporary_mount = pathlib.Path("/run/schulit/backup-register")
    mountpoint_value = device.get("mountpoint")
    if isinstance(mountpoint_value, str) and mountpoint_value:
        mountpoint = pathlib.Path(mountpoint_value)
    else:
        temporary_mount.mkdir(parents=True, exist_ok=True, mode=0o700)
        try:
            subprocess.run(
                ["mount", "-U", str(device["uuid"]), str(temporary_mount)],
                check=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=20,
            )
        except (FileNotFoundError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
            raise SetupError("Der USB-Datenträger konnte nicht vorübergehend eingehängt werden.") from exc
        mountpoint = temporary_mount
        mounted_here = True

    try:
        relative_path = _prepare_backup_folder(mountpoint, device, school_id)
        try:
            subprocess.run(["sync", "-f", str(mountpoint)], check=False, timeout=20)
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
    finally:
        if mounted_here:
            try:
                subprocess.run(["umount", str(temporary_mount)], check=True, timeout=20)
            except (FileNotFoundError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
                raise SetupError(
                    "Der Backupordner wurde angelegt, aber der Datenträger konnte danach nicht sauber ausgehängt werden."
                ) from exc
            try:
                temporary_mount.rmdir()
            except OSError:
                pass

    config = {
        "version": 1,
        "device_uuid": device["uuid"],
        "filesystem": device["fstype"],
        "label": device["label"],
        "model": device["model"],
        "relative_path": relative_path,
        "school_id": school_id,
        "registered_at": now_iso(),
    }
    atomic_write(BACKUP_CONFIG, json.dumps(config, ensure_ascii=False, indent=2) + "\n", 0o600)
    return {"ok": True, "backup": config}


def get_backup_status() -> dict[str, Any]:
    if not BACKUP_CONFIG.exists():
        return {"ok": True, "configured": False}
    try:
        config = json.loads(BACKUP_CONFIG.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SetupError("Backup-Konfiguration ist beschädigt.") from exc
    if not isinstance(config, dict):
        raise SetupError("Backup-Konfiguration ist beschädigt.")
    present = any(str(device["uuid"]) == str(config.get("device_uuid")) for device in list_usb_devices())
    return {"ok": True, "configured": True, "present": present, "backup": config}


def handle(request: dict[str, Any]) -> dict[str, Any]:
    action = request.get("action")
    if action == "status":
        return get_status()
    if action == "initialize":
        payload = request.get("payload")
        if not isinstance(payload, dict):
            raise SetupError("Ungültige Einrichtungsdaten.")
        return initialize(payload)
    if action == "list_backup_devices":
        return {"ok": True, "devices": list_usb_devices()}
    if action == "register_backup_device":
        uuid = request.get("uuid")
        if not isinstance(uuid, str):
            raise SetupError("Ungültige Datenträger-ID.")
        return register_backup_device(uuid)
    if action == "backup_status":
        base = get_backup_status()
        try:
            detail = backup_core.status()
            base.update({
                "encryption_configured": detail.get("encryption_configured", False),
                "last_backup": detail.get("last_backup"),
            })
        except backup_core.BackupError:
            pass
        return base
    if action == "activate_backup_crypto":
        code = request.get("recovery_code")
        if not isinstance(code, str):
            raise SetupError("Recovery-Code fehlt.")
        try:
            return backup_core.configure_crypto(code)
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
    if action == "create_backup":
        try:
            return backup_core.create_backup()
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
    if action == "list_backups":
        try:
            return backup_core.list_backups()
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
    if action == "discover_restore_backups":
        try:
            return backup_core.discover_restore_backups()
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
    if action == "restore_backup":
        selection = request.get("selection")
        code = request.get("recovery_code")
        if not isinstance(selection, dict) or not isinstance(code, str):
            raise SetupError("Ungültige Wiederherstellungsdaten.")
        try:
            return backup_core.restore_backup(selection, code)
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
    if action == "verify_restore_candidate":
        manifest = request.get("manifest")
        code = request.get("recovery_code")
        if not isinstance(manifest, str) or not isinstance(code, str):
            raise SetupError("Ungültige Testdaten.")
        try:
            return backup_core.verify_restore_candidate(manifest, code)
        except backup_core.BackupError as exc:
            raise SetupError(str(exc)) from exc
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
                traceback.print_exc(file=sys.stderr)
                response = {"ok": False, "error": "Interner Setup-Fehler. Details wurden im Systemprotokoll gespeichert."}
            conn.sendall((json.dumps(response, ensure_ascii=False) + "\n").encode("utf-8"))


if __name__ == "__main__":
    try:
        serve()
    except Exception as exc:
        print(f"schulit-setupd: {exc}", file=sys.stderr)
        raise
