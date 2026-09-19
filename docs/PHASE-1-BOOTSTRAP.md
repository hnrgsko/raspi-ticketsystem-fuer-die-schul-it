# Phase 1 – Bootstrap und lokale Serverbasis

## Ziel

Phase 1 installiert noch kein fertiges Ticketsystem. Sie schafft ausschließlich eine reproduzierbare technische Grundlage auf Raspberry Pi OS:

- Systemprüfung
- Apache
- PHP mit benötigten Standardmodulen
- MariaDB
- FHS-nahe Verzeichnisse
- lokale, token-geschützte Setup-Seite
- abschließende Health-Checks

Cloudflare, Domain, USB-Backup, Ticketdatenbank und die eigentliche Anwendung folgen in späteren Schritten.

## Referenzplattform

- Raspberry Pi 3
- Raspberry Pi OS 64-bit
- microSD
- systemd
- Netzwerkzugang

Pi 4 und neuere Modelle sollen mit derselben Installation funktionieren.

## Start aus einem geklonten Repository

```bash
sudo bash install.sh
```

## Geplanter Ein-Befehl-Weg

Der Bootstrap ist bereits so vorbereitet, dass `install.sh` ohne die übrigen Dateien gestartet werden kann. Es lädt dann den Quellstand nach und führt denselben Installer aus.

Während der Entwicklungsphase wird standardmäßig `main` verwendet. Vor einer öffentlichen Freigabe wird dieser Mechanismus auf signierte Releases umgestellt.

## Ergebnis

Nach erfolgreichem Lauf zeigt die Konsole eine lokale URL der Form:

```text
http://192.168.1.50:8080/?token=...
```

Alternativ kann der Token später lokal angezeigt werden:

```bash
sudo cat /var/lib/schulit/setup/bootstrap-token
```

Nach erfolgreicher Tokenprüfung entfernt die Setup-Webseite den Token per Redirect aus der URL und verwendet eine lokale Session.

## Verzeichnisse

```text
/opt/schulit/              statische Anwendung / Releases
/etc/schulit/              Systemkonfiguration
/var/lib/schulit/          persistenter Zustand
/var/backups/schulit/      lokale Backups
/var/log/schulit/          anwendungsspezifische Logs
/var/cache/schulit/        Cache
/run/schulit/              Laufzeitdaten
```

## MariaDB

MariaDB wird explizit an `127.0.0.1` gebunden. In Phase 1 wird noch keine schulische Anwendungsdatenbank und kein Anwendungsbenutzer angelegt.

## Apache

Die Setup-Seite läuft vorläufig auf Port `8080`. Dieser Port dient nur der lokalen Ersteinrichtung.

Die spätere öffentliche Anwendung erhält einen getrennten VirtualHost und wird über den gewählten HTTPS-/Tunnelweg veröffentlicht.

## Sicherheit

- Setup-Token wird kryptografisch zufällig erzeugt.
- Im für Apache lesbaren Zustand liegt nur der SHA-256-Prüfwert.
- Der Klartexttoken liegt root-only unter `/var/lib/schulit/setup/bootstrap-token`.
- MariaDB ist nicht öffentlich gebunden.
- Keine Secrets werden ins Repository geschrieben.
- Der Installer konfiguriert in Phase 1 bewusst noch keine Firewall, um bestehenden SSH-Zugang nicht versehentlich abzuschneiden.

## Wiederholbarkeit

Der Installer ist für wiederholte Ausführung vorbereitet. Bereits vorhandene Verzeichnisse werden nicht gelöscht. Der bestehende Setup-Token bleibt erhalten.

## Noch offen

- eigentliche schulneutrale Ticketanwendung
- Datenbankanlage und Migrationsrunner
- erster System-Administrator
- Recovery-Code
- USB-Backup
- Restore
- Cloudflare-OAuth
- Domain-Assistent
- Update-Dienst
- System-Dashboard
