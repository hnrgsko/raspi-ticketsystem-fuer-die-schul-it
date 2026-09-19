# Phase 3a – USB-Backupmedium registrieren

## Ziel

Phase 3a bereitet den nicht-destruktiven USB-Backupweg vor.

Der Assistent:

1. erkennt angeschlossene USB-Datenträger,
2. zeigt Label, Modell, Größe, Dateisystem, UUID und aktuellen Mountpunkt,
3. lässt einen schreibbaren Datenträger bewusst auswählen,
4. speichert ausschließlich dessen UUID als dauerhafte Identität,
5. legt nur einen eigenen Backupordner an,
6. verändert keine anderen Dateien auf dem Datenträger.

## Nicht-destruktives Verhalten

Der Installer:

- formatiert den Stick nicht,
- löscht keine vorhandenen Dateien,
- verändert keine fremden Verzeichnisse,
- verwendet keinen dauerhaften Gerätenamen wie `/dev/sda1`,
- überschreibt keine bereits vorhandenen fremden Ordner mit gleichem Namen.

Der verwendete Pfad lautet:

```text
SchulIT-Ticketsystem/
└── Backups/
    └── <Schulkennung>/
        ├── archives/
        └── manifests/
```

Zusätzlich werden kleine Markerdateien innerhalb dieses eigenen Ordners angelegt, damit spätere Versionen erkennen können, ob der Ordner tatsächlich von Schul-IT verwaltet wird.

Existiert bereits ein nicht verwalteter Ordner `SchulIT-Ticketsystem` mit Inhalt, bricht die Registrierung sicher ab, statt dort hineinzuschreiben.

## Unterstützte Dateisysteme in Phase 3a

Zunächst werden schreibbare USB-Dateisysteme aus dieser Gruppe akzeptiert:

- ext2/ext3/ext4
- exFAT
- FAT/VFAT
- NTFS/NTFS3

Ein schreibgeschützter Datenträger wird nur angezeigt, aber nicht auswählbar gemacht.

## Mounting

Ist der Datenträger bereits durch Raspberry Pi OS eingehängt, verwendet die Registrierung diesen Mountpunkt.

Ist er nicht eingehängt, wird er für die Registrierung nur vorübergehend unter `/run/schulit/` gemountet und danach wieder sauber ausgehängt.

Es wird in Phase 3a noch kein dauerhafter `fstab`-Eintrag erzeugt.

Die spätere Backup-Routine wird die gespeicherte UUID verwenden und den Datenträger bei Bedarf sicher finden bzw. temporär einhängen.

## Konfiguration

Nach erfolgreicher Registrierung wird root-only gespeichert:

```text
/etc/schulit/backup.json
```

Enthalten sind ausschließlich technische Metadaten wie:

- UUID
- Dateisystem
- Label/Modell
- Schulkennung
- relativer Backupordner
- Registrierungszeitpunkt

## Noch nicht Bestandteil von Phase 3a

Noch nicht implementiert sind:

- eigentliche Backuparchive
- Verschlüsselung
- automatische tägliche Sicherung
- Aufbewahrungsrotation
- Restore aus einem Backup
- Integritätsprüfung eines fertigen Backups

Diese Funktionen folgen nach dem erfolgreichen Hardwaretest der USB-Erkennung und Ordnerregistrierung.
