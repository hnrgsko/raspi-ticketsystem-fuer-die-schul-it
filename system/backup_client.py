#!/usr/bin/env python3
from __future__ import annotations

import json
import socket
import sys

SOCKET_PATH = "/run/schulit/setupd.sock"


def main() -> int:
    request = json.dumps({"action": "create_backup"}, ensure_ascii=False).encode("utf-8") + b"\n"
    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sock.settimeout(360)
    try:
        sock.connect(SOCKET_PATH)
        sock.sendall(request)
        data = b""
        while not data.endswith(b"\n") and len(data) <= 131072:
            chunk = sock.recv(8192)
            if not chunk:
                break
            data += chunk
    except OSError as exc:
        print(f"Schul-IT Backup: Setup-Dienst nicht erreichbar: {exc}", file=sys.stderr)
        return 1
    finally:
        sock.close()

    try:
        response = json.loads(data.decode("utf-8"))
    except Exception:
        print("Schul-IT Backup: Ungültige Antwort des Setup-Dienstes.", file=sys.stderr)
        return 1

    if not isinstance(response, dict) or response.get("ok") is not True:
        message = response.get("error") if isinstance(response, dict) else None
        print(f"Schul-IT Backup fehlgeschlagen: {message or 'unbekannter Fehler'}", file=sys.stderr)
        return 1

    last = response.get("last_backup")
    if isinstance(last, dict):
        print(
            "Schul-IT Backup erfolgreich: "
            f"{last.get('archive', 'Archiv')} "
            f"({last.get('size_bytes', '?')} Bytes)"
        )
    else:
        print("Schul-IT Backup erfolgreich.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
