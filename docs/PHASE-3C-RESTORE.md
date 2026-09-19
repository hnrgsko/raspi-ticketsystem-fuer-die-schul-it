# Phase 3c – Wiederherstellung aus verschlüsseltem USB-Backup

## Ziel

Eine frische Raspberry-Pi-Installation soll ohne vorhandene Schulkonfiguration ein zuvor erzeugtes Schul-IT-Backup erkennen und wiederherstellen können.

## Ablauf

1. Raspberry Pi OS frisch installieren.
2. Schul-IT-Installer starten.
3. Im Browser „Aus Backup wiederherstellen“ auswählen.
4. USB-Stick einstecken.
5. Gefundene Backups anzeigen.
6. gewünschtes Backup auswählen.
7. Recovery-Code eingeben.
8. Backup prüfen und wiederherstellen.
9. automatische Sicherung wieder aktivieren.

## Backup-Erkennung

Die Wiederherstellung benötigt keine bereits vorhandene `/etc/schulit/backup.json`.

Stattdessen durchsucht der privilegierte Setup-Dienst angeschlossene USB-Datenträger nach:

```text
SchulIT-Ticketsystem/
└── Backups/
    └── <Schulkennung>/
        ├── archives/
        ├── manifests/
        └── recovery/
            └── age-identity.json
```

Nur vollständige Kombinationen aus Manifest, Archiv und Recovery-Schlüssel werden angeboten.

## Sicherheitsprüfungen vor der Wiederherstellung

Vor Änderungen am System werden geprüft:

- Manifestformat
- Schulkennung
- Archivname
- Vorhandensein des verschlüsselten Archivs
- SHA-256 des verschlüsselten Archivs
- Recovery-Code / AES-GCM-Authentifizierung
- erfolgreiche age-Entschlüsselung
- internes Backupformat
- sichere Archivpfade

Tar-Archive mit absoluten Pfaden, `..`-Pfaden, Symlinks, Hardlinks oder Gerätedateien werden abgelehnt.

## Recovery-Code

Der Recovery-Code wird nicht auf dem frischen Raspberry Pi benötigt, bevor die Wiederherstellung beginnt.

Er entschlüsselt den auf dem USB-Medium geschützten privaten age-Schlüssel. Ein falscher Recovery-Code führt zu einer authentifizierten Entschlüsselungsfehlermeldung; das Backup wird dann nicht eingespielt.

## Wiederhergestellte Bestandteile

Phase 3c stellt wieder her:

- Datenbank `schulit`
- Datenbank-Anwendungszugang
- Schul-/Anwendungskonfiguration
- Installationsstatus
- Recovery-Prüfdaten
- Backup-Konfiguration
- öffentlicher age-Empfängerschlüssel
- Uploads, soweit im Backup vorhanden

Anschließend wird `schulit-backup.timer` wieder aktiviert.

## Schutz vorhandener Installationen

Die Wiederherstellung ist in Phase 3c ausschließlich zulässig, wenn noch keine Schul-IT-Installation eingerichtet ist.

Existiert bereits `/var/lib/schulit/setup/installation.json`, verweigert der Restore-Dienst die Aktion. Dadurch kann der Einrichtungsassistent nicht versehentlich eine laufende Installation überschreiben.

## Abgebrochene Wiederherstellungen

Solange die Installationsstatusdatei noch nicht geschrieben wurde, kann eine fehlgeschlagene Wiederherstellung erneut versucht werden.

Die dedizierte Datenbank `schulit` wird bei einem erneuten Restore-Versuch kontrolliert neu aufgebaut.

## Noch zu testen

Phase 3c ist implementiert, aber noch nicht auf einer frischen zweiten SD-Karte real getestet.

Der verbindliche Hardwaretest lautet:

1. zweite/frische microSD mit Raspberry Pi OS 64-bit
2. Installer starten
3. vorhandenen Backup-USB-Stick einstecken
4. „Aus Backup wiederherstellen“
5. Backup der Testinstallation auswählen
6. Recovery-Code eingeben
7. wiederhergestellte Schule, Admin, Datenbank und Backupstatus prüfen

Die funktionierende Ausgangs-SD-Karte bleibt während dieses Tests unverändert als Rückfalloption erhalten.


## Nicht-destruktiver Wiederherstellungstest

Falls kein zweiter Boot-Datenträger verfügbar ist, kann die laufende Installation einen vorhandenen Backupstand vollständig prüfen, ohne ihn einzuspielen.

Der Test führt aus:

- Manifestprüfung
- SHA-256-Prüfung des verschlüsselten Archivs
- Prüfung des Recovery-Codes
- Entschlüsselung des geschützten age-Identitätsschlüssels
- Entschlüsselung des Backuparchivs
- sichere Tar-Pfadprüfung
- Prüfung der internen Backup-Metadaten
- Prüfung der Schulkennung
- Prüfung der Anwendungskonfiguration
- Prüfung des Datenbankdumps und der erforderlichen Restore-Dateien

Alle entschlüsselten Testdaten liegen ausschließlich temporär unter `/run/schulit/` und werden nach dem Test entfernt.

Der Test verändert ausdrücklich nicht:

- die laufende MariaDB-Datenbank
- die installierte Konfiguration
- den System-Administrator
- die Uploads
- den registrierten Backupstand

Dieser Test ersetzt den späteren vollständigen Katastrophentest auf einem frischen Boot-Datenträger nicht, gibt aber bereits eine hohe Sicherheit, dass Backup, Recovery-Code und Entschlüsselungskette funktionieren.
