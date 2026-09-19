# Phase 3b – Verschlüsselte Backups

## Ziel

Nach der Registrierung eines USB-Backupmediums kann die Installation verschlüsselte Vollbackups erzeugen.

Gesichert werden aktuell:

- MariaDB-Datenbank `schulit`
- lokale Schul- und Systemkonfiguration
- Installationsstatus
- Recovery-Prüfdaten
- Upload-Verzeichnis, falls vorhanden

## Verschlüsselung

Für die eigentlichen Backuparchive wird `age` mit einem X25519-Empfängerschlüssel verwendet.

Der private age-Identitätsschlüssel wird nicht offen auf dem USB-Stick gespeichert.

Bei der erstmaligen Aktivierung wird er mit einem aus dem Recovery-Code abgeleiteten Schlüssel geschützt:

- KDF: scrypt
- Verschlüsselung des age-Identitätsschlüssels: AES-256-GCM
- Ablage ausschließlich innerhalb des eigenen Backupordners

Der Raspberry Pi behält für automatische Backups nur den öffentlichen age-Empfängerschlüssel. Damit kann er neue Backups verschlüsseln, ohne den privaten Entschlüsselungsschlüssel dauerhaft unverschlüsselt vorzuhalten.

## USB-Struktur

```text
SchulIT-Ticketsystem/
└── Backups/
    └── <Schulkennung>/
        ├── archives/
        │   └── schulit-backup_<Schulkennung>_<Zeitpunkt>.tar.gz.age
        ├── manifests/
        │   └── schulit-backup_<Schulkennung>_<Zeitpunkt>.json
        └── recovery/
            └── age-identity.json
```

Andere Dateien auf dem USB-Datenträger bleiben unangetastet.

## Manifest

Jedes Backup erhält ein separates Manifest mit mindestens:

- Backup-ID
- Schulkennung
- Erstellungszeitpunkt
- Archivname
- SHA-256-Prüfsumme
- Archivgröße
- Verschlüsselungsverfahren

Das Manifest enthält keine Ticketinhalte oder Passwörter.

## Automatische Sicherung

Nach erfolgreicher Aktivierung der Verschlüsselung wird der systemd-Timer `schulit-backup.timer` aktiviert.

Standard:

- täglich gegen 03:30 Uhr
- zufällige Verzögerung bis zu 15 Minuten
- `Persistent=true`, sodass ein verpasster Lauf nachgeholt werden kann

Die Zeit wird später im Expertenbereich konfigurierbar.

## Manuelles Backup

Im Setup-/Systembereich steht zusätzlich `Backup jetzt erstellen` zur Verfügung.

## Wiederherstellung

Die vollständige Wiederherstellung ist Phase 3c.

Geplanter Ablauf:

1. Backupmedium auswählen
2. Manifest und SHA-256 prüfen
3. Recovery-Code eingeben
4. age-Identitätsschlüssel entschlüsseln
5. Backup entschlüsseln
6. Inhalt validieren
7. Datenbank und Konfiguration kontrolliert wiederherstellen
8. Health-Checks ausführen

## Sicherheitsgrenzen

- keine Formatierung des USB-Mediums
- keine Änderung außerhalb von `SchulIT-Ticketsystem/`
- keine Speicherung des Recovery-Codes im Klartext
- kein unverschlüsselter privater age-Schlüssel auf dem USB-Medium
- temporäre Klartextdaten werden unter `/run/schulit` erzeugt und damit auf dem üblichen tmpfs-Laufzeitbereich gehalten
