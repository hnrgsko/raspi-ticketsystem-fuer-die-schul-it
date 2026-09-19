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
import tarfile
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
                "label": str(node.get("label") or "").strip(),
                "model": str(node.get("model") or "").strip(),
                "vendor": str(node.get("vendor") or "").strip(),
                "size_bytes": int(node.get("size") or 0),
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
            _copy_if_exists(BACKUP_RECIPIENT, staging / "etc-schulit" / "backup-recipient.txt")
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


def _mount_device_by_uuid(device: dict[str, Any], suffix: str):
    class _Mount:
        def __init__(self) -> None:
            self.mountpoint: pathlib.Path | None = None
            self.mounted_here = False

        def __enter__(self) -> pathlib.Path:
            current = device.get("mountpoint")
            if isinstance(current, str) and current:
                self.mountpoint = pathlib.Path(current)
                return self.mountpoint

            target = RUN_DIR / suffix
            target.mkdir(parents=True, exist_ok=True, mode=0o700)
            _run(["mount", "-U", str(device["uuid"]), str(target)], timeout=20)
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

    return _Mount()


def discover_restore_backups() -> dict[str, Any]:
    results: list[dict[str, Any]] = []
    for index, device in enumerate(_list_usb_devices()):
        if bool(device.get("read_only")):
            # Read-only media can still be restored from.
            pass
        try:
            with _mount_device_by_uuid(device, f"restore-scan-{index}") as mountpoint:
                root = mountpoint / "SchulIT-Ticketsystem" / "Backups"
                if not root.is_dir() or root.is_symlink():
                    continue

                for school_dir in root.iterdir():
                    if not school_dir.is_dir() or school_dir.is_symlink():
                        continue
                    school_id = school_dir.name
                    if school_id in {"", ".", ".."}:
                        continue
                    manifests_dir = school_dir / "manifests"
                    archives_dir = school_dir / "archives"
                    recovery_path = school_dir / "recovery" / "age-identity.json"
                    if not manifests_dir.is_dir() or not archives_dir.is_dir() or not recovery_path.is_file():
                        continue

                    for manifest_path in sorted(manifests_dir.glob("*.json"), reverse=True):
                        try:
                            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                        except (OSError, json.JSONDecodeError):
                            continue
                        if not isinstance(manifest, dict) or manifest.get("format") != "schulit-backup-manifest-v1":
                            continue
                        archive_name = str(manifest.get("archive") or "")
                        if pathlib.PurePosixPath(archive_name).name != archive_name or not archive_name.endswith(".age"):
                            continue
                        archive_path = archives_dir / archive_name
                        if not archive_path.is_file():
                            continue
                        if str(manifest.get("school_id") or "") != school_id:
                            continue

                        results.append({
                            "device_uuid": str(device["uuid"]),
                            "device_label": str(device.get("label") or ""),
                            "device_model": str(device.get("model") or ""),
                            "device_size_bytes": int(device.get("size_bytes") or 0),
                            "school_id": school_id,
                            "manifest": manifest_path.name,
                            "archive": archive_name,
                            "created_at": str(manifest.get("created_at") or ""),
                            "size_bytes": int(manifest.get("size_bytes") or 0),
                            "sha256": str(manifest.get("sha256") or ""),
                        })
        except BackupError:
            continue

    results.sort(key=lambda item: str(item.get("created_at") or ""), reverse=True)
    return {"ok": True, "backups": results[:50]}


def _derive_restore_key(recovery_code: str, wrapper: dict[str, Any]) -> bytes:
    kdf = wrapper.get("kdf")
    if not isinstance(kdf, dict) or kdf.get("name") != "scrypt":
        raise BackupError("Unbekanntes Schlüsselableitungsverfahren im Backup.")
    try:
        salt = base64.b64decode(str(kdf["salt_b64"]), validate=True)
        n = int(kdf["n"])
        r = int(kdf["r"])
        p = int(kdf["p"])
    except Exception as exc:
        raise BackupError("Schlüsselmetadaten im Backup sind beschädigt.") from exc

    if n < 2**13 or n > 2**16 or r < 1 or r > 16 or p < 1 or p > 8:
        raise BackupError("Schlüsselparameter im Backup liegen außerhalb der erlaubten Grenzen.")

    try:
        return hashlib.scrypt(
            recovery_code.encode("utf-8"),
            salt=salt,
            n=n,
            r=r,
            p=p,
            dklen=32,
            maxmem=128 * 1024 * 1024,
        )
    except ValueError as exc:
        raise BackupError("Der Recovery-Code konnte auf diesem System nicht sicher verarbeitet werden.") from exc


def _unwrap_age_identity(wrapper_path: pathlib.Path, recovery_code: str, expected_school_id: str) -> bytes:
    wrapper = _load_json(wrapper_path, "Verschlüsselter Recovery-Schlüssel")
    if wrapper.get("format") != "schulit-age-identity-wrap-v1":
        raise BackupError("Unbekanntes Recovery-Schlüsselformat.")
    if str(wrapper.get("school_id") or "") != expected_school_id:
        raise BackupError("Recovery-Schlüssel und ausgewähltes Backup gehören nicht zusammen.")

    cipher = wrapper.get("cipher")
    if not isinstance(cipher, dict) or cipher.get("name") != "AES-256-GCM":
        raise BackupError("Unbekanntes Verschlüsselungsverfahren für den Recovery-Schlüssel.")

    try:
        nonce = base64.b64decode(str(cipher["nonce_b64"]), validate=True)
        ciphertext = base64.b64decode(str(cipher["ciphertext_b64"]), validate=True)
    except Exception as exc:
        raise BackupError("Verschlüsselter Recovery-Schlüssel ist beschädigt.") from exc

    key = _derive_restore_key(recovery_code, wrapper)
    aad = f"schulit-backup-key:{expected_school_id}".encode("utf-8")
    try:
        identity = AESGCM(key).decrypt(nonce, ciphertext, aad)
    except Exception as exc:
        raise BackupError("Recovery-Code ist falsch oder der Recovery-Schlüssel ist beschädigt.") from exc

    if b"AGE-SECRET-KEY-" not in identity:
        raise BackupError("Entschlüsselter Recovery-Schlüssel ist ungültig.")
    return identity


def _sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _safe_extract_tar_gz(archive: pathlib.Path, destination: pathlib.Path) -> None:
    destination.mkdir(parents=True, exist_ok=True, mode=0o700)
    root = destination.resolve()
    with tarfile.open(archive, "r:gz") as tar:
        for member in tar.getmembers():
            pure = pathlib.PurePosixPath(member.name)
            if pure.is_absolute() or ".." in pure.parts:
                raise BackupError("Backup enthält einen unsicheren Dateipfad.")
            if member.issym() or member.islnk() or member.isdev():
                raise BackupError("Backup enthält einen nicht erlaubten Dateityp.")

            clean_parts = [part for part in pure.parts if part not in ("", ".")]
            target = root.joinpath(*clean_parts)
            try:
                target.relative_to(root)
            except ValueError as exc:
                raise BackupError("Backup enthält einen ungültigen Dateipfad.") from exc

            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            if not member.isfile():
                continue

            target.parent.mkdir(parents=True, exist_ok=True)
            source = tar.extractfile(member)
            if source is None:
                raise BackupError("Backup-Datei konnte nicht gelesen werden.")
            with source, open(target, "wb") as output:
                shutil.copyfileobj(source, output)
            os.chmod(target, member.mode & 0o777)


def _read_database_access(app_config: pathlib.Path) -> dict[str, Any]:
    try:
        result = _run([
            "php",
            "-r",
            '$c=require $argv[1]; echo json_encode($c["database"] ?? []);',
            str(app_config),
        ], timeout=20)
        data = json.loads(result.stdout.decode("utf-8"))
    except Exception as exc:
        raise BackupError("Datenbankzugang konnte aus dem Backup nicht gelesen werden.") from exc
    if not isinstance(data, dict):
        raise BackupError("Datenbankzugang im Backup ist ungültig.")

    name = str(data.get("name") or "")
    user = str(data.get("user") or "")
    password = str(data.get("password") or "")
    if name != DB_NAME or user != "schulit_app" or len(password) < 20:
        raise BackupError("Datenbankzugang im Backup entspricht nicht dem erwarteten Format.")
    return {"name": name, "user": user, "password": password}


def _restore_database(payload: pathlib.Path, db_access: dict[str, Any]) -> None:
    password = str(db_access["password"])
    escaped = password.replace("\\", "\\\\").replace("'", "\\'")

    # A restore is only allowed before STATE_FILE exists. Reset the dedicated
    # application database so a previously interrupted restore can be retried.
    _run(
        ["mariadb", "--protocol=socket"],
        input_bytes=f"DROP DATABASE IF EXISTS `{DB_NAME}`;\n".encode("utf-8"),
        timeout=60,
    )

    dump = payload / "database.sql"
    if not dump.is_file():
        raise BackupError("Datenbanksicherung fehlt im Backup.")
    try:
        with open(dump, "rb") as source:
            subprocess.run(
                ["mariadb", "--protocol=socket"],
                stdin=source,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=True,
                timeout=240,
            )
    except subprocess.CalledProcessError as exc:
        detail = (exc.stderr or b"").decode("utf-8", errors="replace").strip()
        raise BackupError("Datenbank konnte nicht wiederhergestellt werden" + (f": {detail}" if detail else ".")) from exc
    except subprocess.TimeoutExpired as exc:
        raise BackupError("Datenbank-Wiederherstellung hat zu lange gedauert.") from exc

    access_sql = f"""
DROP USER IF EXISTS 'schulit_app'@'localhost';
CREATE USER 'schulit_app'@'localhost' IDENTIFIED BY '{escaped}';
GRANT SELECT, INSERT, UPDATE, DELETE ON `{DB_NAME}`.* TO 'schulit_app'@'localhost';
FLUSH PRIVILEGES;
"""
    _run(["mariadb", "--protocol=socket"], input_bytes=access_sql.encode("utf-8"), timeout=60)


def _install_restored_file(source: pathlib.Path, destination: pathlib.Path, mode: int, group: str | None = None) -> None:
    if not source.is_file():
        return
    destination.parent.mkdir(parents=True, exist_ok=True)
    tmp = destination.with_name(destination.name + ".restore-tmp")
    shutil.copy2(source, tmp)
    os.chmod(tmp, mode)
    gid = 0
    if group == "www-data":
        import grp
        gid = grp.getgrnam("www-data").gr_gid
    os.chown(tmp, 0, gid)
    os.replace(tmp, destination)


def restore_backup(selection: dict[str, Any], recovery_code: str) -> dict[str, Any]:
    if STATE_FILE.exists():
        raise BackupError(
            "Auf diesem Raspberry Pi ist bereits eine Installation eingerichtet. "
            "Die Wiederherstellung ist nur auf einer frischen bzw. noch nicht eingerichteten Installation erlaubt."
        )

    device_uuid = str(selection.get("device_uuid") or "")
    school_id = str(selection.get("school_id") or "")
    manifest_name = str(selection.get("manifest") or "")
    if not device_uuid or not school_id or pathlib.PurePosixPath(manifest_name).name != manifest_name:
        raise BackupError("Ungültige Backup-Auswahl.")

    device = next((item for item in _list_usb_devices() if str(item["uuid"]) == device_uuid), None)
    if device is None:
        raise BackupError("Der ausgewählte USB-Datenträger ist nicht angeschlossen.")

    with _mount_device_by_uuid(device, "restore-selected") as mountpoint:
        school_dir = mountpoint / "SchulIT-Ticketsystem" / "Backups" / school_id
        manifest_path = school_dir / "manifests" / manifest_name
        recovery_path = school_dir / "recovery" / "age-identity.json"
        manifest = _load_json(manifest_path, "Backup-Manifest")
        if manifest.get("format") != "schulit-backup-manifest-v1":
            raise BackupError("Unbekanntes Backup-Manifest.")
        if str(manifest.get("school_id") or "") != school_id:
            raise BackupError("Backup-Manifest gehört zu einer anderen Schulkennung.")

        archive_name = str(manifest.get("archive") or "")
        if pathlib.PurePosixPath(archive_name).name != archive_name:
            raise BackupError("Ungültiger Archivname im Manifest.")
        archive_path = school_dir / "archives" / archive_name
        if not archive_path.is_file():
            raise BackupError("Backup-Archiv fehlt.")

        expected_hash = str(manifest.get("sha256") or "").lower()
        if len(expected_hash) != 64 or _sha256_file(archive_path) != expected_hash:
            raise BackupError("SHA-256-Prüfung des Backup-Archivs ist fehlgeschlagen.")

        identity = _unwrap_age_identity(recovery_path, recovery_code, school_id)

        with tempfile.TemporaryDirectory(prefix="schulit-restore-", dir=str(RUN_DIR)) as tmpdir:
            tmp = pathlib.Path(tmpdir)
            identity_path = tmp / "identity.txt"
            identity_path.write_bytes(identity)
            os.chmod(identity_path, 0o600)

            decrypted = tmp / "backup.tar.gz"
            _run([
                "age", "-d",
                "-i", str(identity_path),
                "-o", str(decrypted),
                str(archive_path),
            ], timeout=300)

            payload = tmp / "payload"
            _safe_extract_tar_gz(decrypted, payload)

            payload_meta = _load_json(payload / "backup.json", "Backup-Inhaltsmanifest")
            if payload_meta.get("format") != "schulit-backup-payload-v1":
                raise BackupError("Unbekanntes Backup-Inhaltsformat.")
            if str(payload_meta.get("school_id") or "") != school_id:
                raise BackupError("Entschlüsseltes Backup gehört nicht zur ausgewählten Schulkennung.")

            app_config = payload / "etc-schulit" / "app.php"
            db_access = _read_database_access(app_config)
            _restore_database(payload, db_access)

            _install_restored_file(app_config, pathlib.Path("/etc/schulit/app.php"), 0o640, "www-data")
            _install_restored_file(payload / "etc-schulit" / "system.conf", pathlib.Path("/etc/schulit/system.conf"), 0o640)
            _install_restored_file(payload / "etc-schulit" / "backup.json", BACKUP_CONFIG, 0o600)
            _install_restored_file(payload / "etc-schulit" / "backup-crypto.json", BACKUP_CRYPTO, 0o600)

            recipient_source = payload / "etc-schulit" / "backup-recipient.txt"
            if recipient_source.is_file():
                _install_restored_file(recipient_source, BACKUP_RECIPIENT, 0o644)
            else:
                crypto = _load_json(payload / "etc-schulit" / "backup-crypto.json", "Backup-Verschlüsselung")
                recipient = str(crypto.get("recipient") or "")
                if not recipient.startswith("age1"):
                    raise BackupError("Öffentlicher Backup-Schlüssel fehlt im Backup.")
                _atomic_write(BACKUP_RECIPIENT, recipient + "\n", 0o644)

            _install_restored_file(payload / "state" / "installation.json", STATE_FILE, 0o640, "www-data")
            _install_restored_file(payload / "state" / "recovery.json", RECOVERY_FILE, 0o600)

            uploads = payload / "uploads"
            if uploads.is_dir():
                if UPLOADS_DIR.exists():
                    shutil.rmtree(UPLOADS_DIR)
                shutil.copytree(uploads, UPLOADS_DIR)
                os.chown(UPLOADS_DIR, 0, 0)

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
            "Wiederherstellung war erfolgreich, aber der automatische Backup-Timer konnte nicht aktiviert werden."
        ) from exc

    restored_state = _load_json(STATE_FILE, "Wiederhergestellter Installationsstatus")
    return {
        "ok": True,
        "restored": True,
        "school_id": school_id,
        "school_name": str(restored_state.get("school_name") or ""),
        "admin_username": str(restored_state.get("admin_username") or ""),
        "backup_created_at": str(manifest.get("created_at") or ""),
        "archive": archive_name,
    }
