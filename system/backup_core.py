#!/usr/bin/env python3
from __future__ import annotations

import base64
import datetime as dt
import hashlib
import json
import os
import pathlib
import secrets
import shutil
import subprocess
import tempfile
from typing import Any

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

CONFIG_DIR = pathlib.Path("/etc/schulit")
BACKUP_CONFIG = CONFIG_DIR / "backup.json"
BACKUP_CRYPTO = CONFIG_DIR / "backup-crypto.json"
BACKUP_RECIPIENT = CONFIG_DIR / "backup-recipient.txt"

STATE_FILE = pathlib.Path("/var/lib/schulit/setup/installation.json")
RECOVERY_FILE = pathlib.Path("/var/lib/schulit/recovery/recovery.json")
BACKUP_STATUS_FILE = pathlib.Path("/var/lib/schulit/backup-status.json")
UPLOADS_DIR = pathlib.Path("/var/lib/schulit/uploads")
RUN_DIR = pathlib.Path("/run/schulit")

DB_NAME = "schulit"


class BackupError(Exception):
    pass


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def _atomic_write(path: pathlib.Path, data: str, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + ".tmp")
    with open(tmp, "w", encoding="utf-8") as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(tmp, mode)
    os.chown(tmp, 0, 0)
    os.replace(tmp, path)


def _load_json(path: pathlib.Path, label: str) -> dict[str, Any]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise BackupError(f"{label} fehlt.") from exc
    except (OSError, json.JSONDecodeError) as exc:
        raise BackupError(f"{label} ist beschädigt.") from exc
    if not isinstance(data, dict):
        raise BackupError(f"{label} ist ungültig.")
    return data


def _run(command: list[str], *, input_bytes: bytes | None = None, timeout: int = 120) -> subprocess.CompletedProcess[bytes]:
    try:
        return subprocess.run(
            command,
            input=input_bytes,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=True,
            timeout=timeout,
        )
    except FileNotFoundError as exc:
        raise BackupError(f"Benötigtes Programm fehlt: {command[0]}") from exc
    except subprocess.TimeoutExpired as exc:
        raise BackupError(f"Aktion hat zu lange gedauert: {command[0]}") from exc
    except subprocess.CalledProcessError as exc:
        detail = (exc.stderr or b"").decode("utf-8", errors="replace").strip()
        raise BackupError(
            f"Aktion fehlgeschlagen: {command[0]}" + (f" – {detail}" if detail else "")
        ) from exc


def _list_usb_devices() -> list[dict[str, Any]]:
    result = _run([
        "lsblk", "--json", "--bytes", "--paths",
        "-o", "NAME,PATH,TYPE,SIZE,FSTYPE,LABEL,UUID,MOUNTPOINTS,RM,RO,TRAN,MODEL,VENDOR"
    ], timeout=20)
    try:
        tree = json.loads(result.stdout.decode("utf-8"))
    except json.JSONDecodeError as exc:
        raise BackupError("USB-Datenträger konnten nicht zuverlässig erkannt werden.") from exc

    devices: list[dict[str, Any]] = []

    def mountpoint(node: dict[str, Any]) -> str | None:
        values = node.get("mountpoints")
        if isinstance(values, list):
            for value in values:
                if isinstance(value, str) and value:
                    return value
        value = node.get("mountpoint")
        return value if isinstance(value, str) and value else None

    def walk(node: dict[str, Any], parent_usb: bool = False) -> None:
        is_usb = parent_usb or str(node.get("tran") or "").lower() == "usb"
        uuid = str(node.get("uuid") or "").strip()
        fstype = str(node.get("fstype") or "").strip().lower()
        path = str(node.get("path") or node.get("name") or "")
        node_type = str(node.get("type") or "")
        if is_usb and uuid and fstype and path and node_type in {"part", "disk"}:
            devices.append({
                "uuid": uuid,
                "path": path,
                "mountpoint": mountpoint(node),
                "fstype": fstype,
                "read_only": bool(node.get("ro")),
            })
        children = node.get("children")
        if isinstance(children, list):
            for child in children:
                if isinstance(child, dict):
                    walk(child, is_usb)

    for entry in tree.get("blockdevices", []):
        if isinstance(entry, dict):
            walk(entry)
    return devices


def _device_for_config(config: dict[str, Any]) -> dict[str, Any]:
    expected = str(config.get("device_uuid") or "")
    for device in _list_usb_devices():
        if secrets.compare_digest(str(device["uuid"]), expected):
            if device.get("read_only"):
                raise BackupError("Das konfigurierte USB-Backupmedium ist schreibgeschützt.")
            return device
    raise BackupError("Das konfigurierte USB-Backupmedium ist nicht angeschlossen.")


class MountedBackup:
    def __init__(self, config: dict[str, Any]):
        self.config = config
        self.device = _device_for_config(config)
        self.mountpoint: pathlib.Path | None = None
        self.mounted_here = False

    def __enter__(self) -> pathlib.Path:
        current = self.device.get("mountpoint")
        if isinstance(current, str) and current:
            self.mountpoint = pathlib.Path(current)
            return self.mountpoint

        target = RUN_DIR / "backup-mounted"
        target.mkdir(parents=True, exist_ok=True, mode=0o700)
        _run(["mount", "-U", str(self.device["uuid"]), str(target)], timeout=20)
        self.mountpoint = target
        self.mounted_here = True
        return target

    def __exit__(self, exc_type: Any, exc: Any, tb: Any) -> None:
        if self.mounted_here and self.mountpoint is not None:
            try:
                _run(["umount", str(self.mountpoint)], timeout=20)
            finally:
                try:
                    self.mountpoint.rmdir()
                except OSError:
                    pass


def _backup_school_dir(mountpoint: pathlib.Path, config: dict[str, Any]) -> pathlib.Path:
    rel = str(config.get("relative_path") or "")
    school_id = str(config.get("school_id") or "")
    expected = f"SchulIT-Ticketsystem/Backups/{school_id}"
    if rel != expected:
        raise BackupError("Der gespeicherte Backup-Pfad ist ungültig.")

    path = mountpoint / rel
    parts = [
        mountpoint / "SchulIT-Ticketsystem",
        mountpoint / "SchulIT-Ticketsystem" / "Backups",
        path,
    ]
    for item in parts:
        if item.is_symlink():
            raise BackupError("Der Backup-Pfad enthält einen symbolischen Link und wird nicht verwendet.")
        if not item.is_dir():
            raise BackupError("Der konfigurierte Backup-Ordner fehlt.")
    return path


def _verify_recovery_code(code: str) -> str:
    if not isinstance(code, str) or len(code) < 20 or len(code) > 200:
        raise BackupError("Recovery-Code ist ungültig.")

    record = _load_json(RECOVERY_FILE, "Recovery-Prüfwert")
    if record.get("scheme") != "scrypt":
        raise BackupError("Unbekanntes Recovery-Verfahren.")
    try:
        salt = base64.b64decode(str(record["salt_b64"]), validate=True)
        expected = base64.b64decode(str(record["hash_b64"]), validate=True)
    except Exception as exc:
        raise BackupError("Recovery-Prüfwert ist beschädigt.") from exc

    derived = hashlib.scrypt(
        code.encode("utf-8"),
        salt=salt,
        n=2**14,
        r=8,
        p=1,
        dklen=32,
    )
    if not secrets.compare_digest(derived, expected):
        raise BackupError("Der Recovery-Code ist nicht korrekt.")

    school_id = str(record.get("school_id") or "")
    if school_id == "":
        raise BackupError("Schulkennung im Recovery-Datensatz fehlt.")
    return school_id


def crypto_status() -> dict[str, Any]:
    configured = BACKUP_CRYPTO.exists() and BACKUP_RECIPIENT.exists()
    data: dict[str, Any] = {"ok": True, "configured": configured}
    if configured:
        crypto = _load_json(BACKUP_CRYPTO, "Backup-Verschlüsselung")
        data["recipient"] = str(crypto.get("recipient") or "")
        data["configured_at"] = str(crypto.get("configured_at") or "")
    return data


def configure_crypto(recovery_code: str) -> dict[str, Any]:
    if BACKUP_CRYPTO.exists() or BACKUP_RECIPIENT.exists():
        return crypto_status()

    config = _load_json(BACKUP_CONFIG, "Backup-Konfiguration")
    school_id = _verify_recovery_code(recovery_code)
    if school_id != str(config.get("school_id") or ""):
        raise BackupError("Recovery-Code und Backup-Konfiguration gehören nicht zur selben Schulinstallation.")

    with MountedBackup(config) as mountpoint:
        school_dir = _backup_school_dir(mountpoint, config)
        recovery_dir = school_dir / "recovery"
        if recovery_dir.is_symlink():
            raise BackupError("Der Recovery-Ordner ist ein symbolischer Link und wird nicht verwendet.")
        recovery_dir.mkdir(mode=0o755, exist_ok=True)

        wrapped_path = recovery_dir / "age-identity.json"
        if wrapped_path.exists():
            raise BackupError(
                "Auf dem Backupmedium existiert bereits ein verschlüsselter Entschlüsselungsschlüssel. "
                "Er wird nicht automatisch überschrieben."
            )

        with tempfile.TemporaryDirectory(prefix="schulit-age-", dir=str(RUN_DIR)) as tmpdir:
            identity_path = pathlib.Path(tmpdir) / "identity.txt"
            _run(["age-keygen", "-o", str(identity_path)], timeout=20)
            recipient = _run(["age-keygen", "-y", str(identity_path)], timeout=20).stdout.decode("utf-8").strip()
            if not recipient.startswith("age1"):
                raise BackupError("Der age-Empfängerschlüssel konnte nicht erzeugt werden.")
            identity = identity_path.read_bytes()

            salt = secrets.token_bytes(16)
            try:
                key = hashlib.scrypt(
                    recovery_code.encode("utf-8"),
                    salt=salt,
                    n=2**14,
                    r=8,
                    p=1,
                    dklen=32,
                    maxmem=64 * 1024 * 1024,
                )
            except ValueError as exc:
                raise BackupError(
                    "Die Schlüsselableitung für die Backup-Verschlüsselung ist auf diesem System "
                    "an ein Speicherlimit gestoßen."
                ) from exc
            nonce = secrets.token_bytes(12)
            aad = f"schulit-backup-key:{school_id}".encode("utf-8")
            ciphertext = AESGCM(key).encrypt(nonce, identity, aad)

        wrapper = {
            "format": "schulit-age-identity-wrap-v1",
            "school_id": school_id,
            "recipient": recipient,
            "kdf": {
                "name": "scrypt",
                "n": 2**14,
                "r": 8,
                "p": 1,
                "salt_b64": base64.b64encode(salt).decode("ascii"),
            },
            "cipher": {
                "name": "AES-256-GCM",
                "nonce_b64": base64.b64encode(nonce).decode("ascii"),
                "ciphertext_b64": base64.b64encode(ciphertext).decode("ascii"),
            },
            "created_at": now_iso(),
        }

        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        fd = os.open(wrapped_path, flags, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(wrapper, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())

        _run(["sync", "-f", str(mountpoint)], timeout=20)

    _atomic_write(BACKUP_RECIPIENT, recipient + "\n", 0o644)
    _atomic_write(BACKUP_CRYPTO, json.dumps({
        "version": 1,
        "recipient": recipient,
        "wrapped_identity": f"{config['relative_path']}/recovery/age-identity.json",
        "configured_at": now_iso(),
    }, ensure_ascii=False, indent=2) + "\n", 0o600)

    try:
        subprocess.run(
            ["systemctl", "enable", "--now", "schulit-backup.timer"],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=20,
        )
    except Exception as exc:
        raise BackupError(
            "Verschlüsselung wurde eingerichtet, aber der automatische Backup-Timer konnte nicht aktiviert werden."
        ) from exc

    return crypto_status()


def _copy_if_exists(source: pathlib.Path, target: pathlib.Path) -> None:
    if source.is_file():
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)


def _dump_database(target: pathlib.Path) -> None:
    with open(target, "wb") as handle:
        try:
            completed = subprocess.run(
                [
                    "mariadb-dump",
                    "--protocol=socket",
                    "--single-transaction",
                    "--quick",
                    "--skip-lock-tables",
                    "--databases",
                    DB_NAME,
                ],
                stdout=handle,
                stderr=subprocess.PIPE,
                check=True,
                timeout=180,
            )
        except FileNotFoundError as exc:
            raise BackupError("mariadb-dump wurde nicht gefunden.") from exc
        except subprocess.TimeoutExpired as exc:
            raise BackupError("Datenbanksicherung hat zu lange gedauert.") from exc
        except subprocess.CalledProcessError as exc:
            detail = (exc.stderr or b"").decode("utf-8", errors="replace").strip()
            raise BackupError("Datenbanksicherung fehlgeschlagen" + (f": {detail}" if detail else ".")) from exc


def _write_backup_status(payload: dict[str, Any]) -> None:
    BACKUP_STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    _atomic_write(BACKUP_STATUS_FILE, json.dumps(payload, ensure_ascii=False, indent=2) + "\n", 0o640)
    os.chown(BACKUP_STATUS_FILE, 0, os.getgid())


def create_backup() -> dict[str, Any]:
    config = _load_json(BACKUP_CONFIG, "Backup-Konfiguration")
    crypto = _load_json(BACKUP_CRYPTO, "Backup-Verschlüsselung")
    recipient = BACKUP_RECIPIENT.read_text(encoding="utf-8").strip()
    if not recipient.startswith("age1") or recipient != str(crypto.get("recipient") or ""):
        raise BackupError("Backup-Empfängerschlüssel ist ungültig.")

    state = _load_json(STATE_FILE, "Installationsstatus")
    school_id = str(state.get("school_id") or "")
    if school_id != str(config.get("school_id") or ""):
        raise BackupError("Installationsstatus und Backup-Konfiguration passen nicht zusammen.")

    backup_id = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "_" + secrets.token_hex(3)
    archive_name = f"schulit-backup_{school_id}_{backup_id}.tar.gz.age"
    manifest_name = f"schulit-backup_{school_id}_{backup_id}.json"

    with MountedBackup(config) as mountpoint:
        school_dir = _backup_school_dir(mountpoint, config)
        archives = school_dir / "archives"
        manifests = school_dir / "manifests"
        for directory in (archives, manifests):
            if directory.is_symlink() or not directory.is_dir():
                raise BackupError("Backup-Unterordner ist ungültig.")

        archive_path = archives / archive_name
        manifest_path = manifests / manifest_name
        if archive_path.exists() or manifest_path.exists():
            raise BackupError("Backup-Dateiname existiert bereits.")

        with tempfile.TemporaryDirectory(prefix="schulit-backup-", dir=str(RUN_DIR)) as tmpdir:
            staging = pathlib.Path(tmpdir) / "payload"
            staging.mkdir(mode=0o700)

            _dump_database(staging / "database.sql")
            _copy_if_exists(pathlib.Path("/etc/schulit/app.php"), staging / "etc-schulit" / "app.php")
            _copy_if_exists(pathlib.Path("/etc/schulit/system.conf"), staging / "etc-schulit" / "system.conf")
            _copy_if_exists(BACKUP_CONFIG, staging / "etc-schulit" / "backup.json")
            _copy_if_exists(BACKUP_CRYPTO, staging / "etc-schulit" / "backup-crypto.json")
            _copy_if_exists(STATE_FILE, staging / "state" / "installation.json")
            _copy_if_exists(RECOVERY_FILE, staging / "state" / "recovery.json")
            if UPLOADS_DIR.is_dir():
                shutil.copytree(UPLOADS_DIR, staging / "uploads", dirs_exist_ok=True)

            payload_manifest = {
                "format": "schulit-backup-payload-v1",
                "school_id": school_id,
                "school_name": str(state.get("school_name") or ""),
                "created_at": now_iso(),
                "database": DB_NAME,
            }
            (staging / "backup.json").write_text(
                json.dumps(payload_manifest, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )

            temp_archive = archive_path.with_suffix(archive_path.suffix + ".tmp")
            tar = subprocess.Popen(
                ["tar", "-C", str(staging), "-czf", "-", "."],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            assert tar.stdout is not None
            try:
                age = subprocess.run(
                    ["age", "-r", recipient, "-o", str(temp_archive)],
                    stdin=tar.stdout,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    check=True,
                    timeout=300,
                )
            except FileNotFoundError as exc:
                tar.kill()
                raise BackupError("age wurde nicht gefunden.") from exc
            except subprocess.TimeoutExpired as exc:
                tar.kill()
                raise BackupError("Backup-Verschlüsselung hat zu lange gedauert.") from exc
            except subprocess.CalledProcessError as exc:
                tar.kill()
                detail = (exc.stderr or b"").decode("utf-8", errors="replace").strip()
                raise BackupError("Backup-Verschlüsselung fehlgeschlagen" + (f": {detail}" if detail else ".")) from exc
            finally:
                tar.stdout.close()

            tar_stderr = tar.stderr.read() if tar.stderr is not None else b""
            tar_rc = tar.wait(timeout=60)
            if tar_rc != 0:
                try:
                    temp_archive.unlink()
                except FileNotFoundError:
                    pass
                detail = tar_stderr.decode("utf-8", errors="replace").strip()
                raise BackupError("Backup-Archivierung fehlgeschlagen" + (f": {detail}" if detail else "."))

            os.replace(temp_archive, archive_path)

        digest = hashlib.sha256()
        with open(archive_path, "rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)

        manifest = {
            "format": "schulit-backup-manifest-v1",
            "backup_id": backup_id,
            "school_id": school_id,
            "created_at": now_iso(),
            "archive": archive_name,
            "sha256": digest.hexdigest(),
            "size_bytes": archive_path.stat().st_size,
            "encrypted": True,
            "encryption": "age-x25519",
        }
        temp_manifest = manifest_path.with_suffix(".json.tmp")
        temp_manifest.write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        os.chmod(temp_manifest, 0o600)
        os.replace(temp_manifest, manifest_path)
        _run(["sync", "-f", str(mountpoint)], timeout=30)

    status = {
        "ok": True,
        "last_backup": manifest,
        "completed_at": now_iso(),
    }
    _atomic_write(BACKUP_STATUS_FILE, json.dumps(status, ensure_ascii=False, indent=2) + "\n", 0o640)
    return status


def list_backups() -> dict[str, Any]:
    config = _load_json(BACKUP_CONFIG, "Backup-Konfiguration")
    with MountedBackup(config) as mountpoint:
        school_dir = _backup_school_dir(mountpoint, config)
        manifests_dir = school_dir / "manifests"
        items: list[dict[str, Any]] = []
        for path in sorted(manifests_dir.glob("*.json"), reverse=True)[:25]:
            try:
                item = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if isinstance(item, dict) and item.get("format") == "schulit-backup-manifest-v1":
                items.append(item)
    return {"ok": True, "backups": items}


def status() -> dict[str, Any]:
    result: dict[str, Any] = {
        "ok": True,
        "configured": BACKUP_CONFIG.exists(),
        "encryption_configured": BACKUP_CRYPTO.exists() and BACKUP_RECIPIENT.exists(),
    }
    if BACKUP_STATUS_FILE.exists():
        try:
            last = json.loads(BACKUP_STATUS_FILE.read_text(encoding="utf-8"))
            if isinstance(last, dict):
                result["last_backup"] = last.get("last_backup")
        except (OSError, json.JSONDecodeError):
            pass
    return result
