# Update-Service

## Ziel

Alle installierten Raspberry-Pi-Instanzen sollen neue veröffentlichte Versionen selbst erkennen.

Sobald eine neuere stabile Version verfügbar ist, sehen berechtigte Administratoren in der Oberfläche:

> **Eine neue Version ist verfügbar. Möchten Sie das Update installieren?**

Die Schule muss dazu keine Git-Befehle ausführen, keine Dateien kopieren und sich nicht auf einem zentralen Kundenportal registrieren.

## Grundprinzip

Die installierte Instanz fragt in regelmäßigen Abständen ausschließlich öffentlich verfügbare Release-Metadaten ab.

Die Instanz sendet dabei keine Ticketdaten, Benutzernamen, Schulnamen oder Inhaltsdaten an das Projekt.

Für öffentliche GitHub-Repositories kann der jeweils aktuelle veröffentlichte Release über die GitHub-Releases-API abgefragt werden. Release-Assets können für öffentliche Ressourcen ohne eigenes GitHub-Zugriffstoken heruntergeladen werden.

## Versionierung

Geplant ist Semantic Versioning:

- MAJOR: nicht rückwärtskompatible Änderung
- MINOR: neue rückwärtskompatible Funktionen
- PATCH: Fehler- und Sicherheitskorrekturen

Beispiel:

`1.4.2`

Updatekanäle:

- `stable` – Standard für Schulen
- `beta` – optional für Testinstanzen
- `dev` – nur für Entwicklung

Eine frische Installation verwendet ausschließlich `stable`.

## Releasepaket

Ein freigegebenes Release soll mindestens enthalten:

```text
ticketsystem-1.4.2.tar.zst
manifest.json
manifest.sig
SHA256SUMS
release-notes.md
```

Das Manifest enthält unter anderem:

- Version
- Releasekanal
- Veröffentlichungszeitpunkt
- Mindestversion des Installers
- unterstützte Raspberry-Pi-OS-Versionen
- erforderliche PHP-Version
- erforderliche Datenbankmigrationen
- SHA256-Prüfsumme des Pakets
- Größe des Pakets
- Neustartanforderungen
- optionale Sicherheitskennzeichnung

## Signaturprüfung

Eine reine HTTPS-Verbindung reicht für unseren Updateprozess nicht als einzige Vertrauensprüfung.

Der Installer bringt einen öffentlichen Projektschlüssel mit. Neue Release-Manifeste werden mit dem zugehörigen privaten Release-Schlüssel signiert.

Vor einer Installation müssen mindestens folgende Prüfungen erfolgreich sein:

1. Quelle entspricht der fest konfigurierten Releasequelle.
2. Manifest ist syntaktisch gültig.
3. digitale Signatur des Manifests ist gültig.
4. gewünschte Version ist neuer als die installierte Version.
5. Releasekanal ist erlaubt.
6. SHA256 des heruntergeladenen Pakets entspricht dem signierten Manifest.
7. benötigter freier Speicher ist vorhanden.
8. Systemplattform und Abhängigkeiten sind kompatibel.

Bei einer fehlgeschlagenen Prüfung wird das Paket niemals ausgeführt oder installiert.

## Update-Prüfung

Ein lokaler Dienst bzw. systemd-Timer prüft beispielsweise alle sechs Stunden auf neue Releases.

Das Intervall soll im Expertenmodus veränderbar sein.

Keine neue Version:

```text
Installierte Version: 1.4.1
Aktuelle Version:     1.4.1
Status: aktuell
```

Neue Version:

```text
Neue Version verfügbar

1.4.1 → 1.4.2

[Änderungen ansehen]
[Später]
[Update installieren]
```

Die Prüfung darf auch manuell über „Jetzt nach Updates suchen“ ausgelöst werden.

## Benachrichtigung

Die Update-Meldung muss im normalen Adminbereich des Ticketsystems sichtbar sein. Die Systemverwaltung allein reicht nicht aus, weil sie im täglichen Betrieb möglicherweise nur selten geöffnet wird.

### Ticket-Administration

Für berechtigte Administratoren erscheint bei einer verfügbaren neuen Version ein gut sichtbarer, aber nicht störender Banner oberhalb der normalen Ticketansicht:

> **Neue Version 1.4.2 verfügbar**
>
> Dieses Update enthält Verbesserungen und Fehlerbehebungen.
>
> [Änderungen ansehen] [Später] [Update installieren]

Der Banner soll auf allen zentralen Adminseiten sichtbar bleiben, bis einer der folgenden Zustände eintritt:

- Update wurde installiert.
- Administrator hat die Meldung für die aktuelle Sitzung bzw. bis zur nächsten Updateprüfung geschlossen.
- Version wurde zurückgezogen oder ist nicht mehr für den verwendeten Updatekanal verfügbar.

Im Adminbereich soll zusätzlich dauerhaft eine kleine Versionsanzeige vorhanden sein, beispielsweise im Footer oder im Account-/Systemmenü:

`Version 1.4.1 · Update verfügbar`

Ist kein Update verfügbar:

`Version 1.4.1 · aktuell`

Nur Benutzer mit System-Update-Berechtigung dürfen „Update installieren“ auslösen. Andere Backend-Benutzer können optional nur den Hinweis „Neue Version verfügbar“ sehen oder gar keinen Updatehinweis erhalten; dies wird über Rollenrechte festgelegt.

### Systemverwaltung

Die Systemverwaltung enthält zusätzlich die vollständige Updatekarte mit:

- installierter Version
- verfügbarer Version
- Release Notes
- Veröffentlichungsdatum
- Updategröße
- Updatekanal
- letztem Prüfzeitpunkt
- letztem erfolgreichen Backup
- Status des Updaters
- Signaturstatus
- benötigten Datenbankmigrationen
- Neustartanforderungen
- Updateverlauf

Damit gilt:

- **Adminbereich:** Hinweis sehen und Update bequem starten.
- **Systemverwaltung:** technische Details, Verlauf, Diagnose und Rollback.

Optional kann später zusätzlich eine E-Mail an Systemadministratoren versendet werden.

## Keine zentrale Client-Datenbank erforderlich

Der Update-Service soll standardmäßig ohne Telemetrie funktionieren.

Das Projekt muss nicht wissen:

- welche Schule das System nutzt
- unter welcher Domain es läuft
- wie viele Tickets existieren
- welche Version eine konkrete Schule verwendet

Jede Instanz fragt nur die öffentliche Releasequelle ab.

Wenn später freiwillige Telemetrie gewünscht wird, muss sie separat, transparent und standardmäßig deaktiviert implementiert werden.

## Sicherheitsarchitektur

Der PHP-Webprozess darf nicht beliebige Root-Befehle ausführen.

Geplant ist ein eigener lokaler Update-Dienst:

```text
Browser
  ↓
Systemverwaltung
  ↓
PHP
  ↓
lokale Unix-Socket-API
  ↓
schulit-updater (root)
  ↓
ausschließlich fest definierte Updateaktionen
```

Der Update-Dienst akzeptiert keine beliebigen Shellkommandos und keine beliebigen Download-URLs.

Er kennt ausschließlich:

- Status abrufen
- nach Update suchen
- freigegebene Version herunterladen
- freigegebene Version installieren
- Updateverlauf lesen
- kontrollierten Rollback starten

Die Releasequelle und der Signaturprüfschlüssel liegen in einer rootgeschützten Systemkonfiguration.

## Installationslayout

Für Updates eignet sich eine Release-Struktur wie:

```text
/opt/schulit/
├── current -> releases/1.4.2
├── releases/
│   ├── 1.4.0/
│   ├── 1.4.1/
│   └── 1.4.2/
├── shared/
│   ├── uploads/
│   └── persistent-data/
└── updater/
```

Private Konfiguration, Datenbank und Uploaddaten liegen nicht innerhalb eines austauschbaren Release-Verzeichnisses.

Dadurch kann eine neue Anwendungsversion zunächst vollständig neben der aktiven Version installiert werden.

## Ablauf nach Bestätigung

### 1. Vorprüfung

Prüfen:

- laufende Version
- Betriebssystem
- CPU-Architektur
- PHP
- MariaDB
- freier Speicher
- Datenbankverbindung
- Backupziel
- Tunnelstatus
- laufende Jobs

### 2. Backup

Vor jedem Update automatisch:

- MariaDB-Dump
- private Konfiguration
- relevante Systemkonfiguration
- optional Upload-Metadaten

Das Update darf nicht fortgesetzt werden, wenn das vorgeschriebene Backup fehlschlägt.

### 3. Download

Das Release wird zunächst in einen Staging-Bereich geladen.

### 4. Verifikation

Signatur und Hash werden geprüft.

### 5. Wartungsmodus

Öffentliche Nutzer sehen kurz:

> Das Ticketsystem wird gerade aktualisiert. Bitte versuchen Sie es in wenigen Minuten erneut.

Die Systemverwaltung bleibt für den lokalen Administrator soweit möglich zugänglich.

### 6. Installation

Neue Dateien werden in ein neues Release-Verzeichnis entpackt.

Konfigurationsdateien und Uploads werden nicht überschrieben.

### 7. Migrationen

Ein Migrationsrunner führt nur noch nicht ausgeführte Migrationen in definierter Reihenfolge aus.

Dafür benötigt die portable Version eine eigene lokale Migrationshistorie.

### 8. Umschalten

Nach erfolgreichen Migrationen wird der `current`-Symlink atomar auf die neue Version gesetzt.

### 9. Health-Checks

Mindestens:

- PHP antwortet
- Datenbank erreichbar
- Schema-Version korrekt
- Admin-Endpunkt lokal erreichbar
- öffentliche Anwendung lokal erreichbar
- Tunnel-Dienst läuft

### 10. Abschluss

Bei Erfolg:

- Wartungsmodus beenden
- Update als erfolgreich protokollieren
- installierte Version aktualisieren
- Administrator informieren

## Fehler und Rollback

### Fehler vor Datenbankmigrationen

Neue Version verwerfen; aktive Version bleibt unverändert.

### Fehler nach Datenbankmigrationen

Anwendungsversion und Datenbankschema müssen gemeinsam betrachtet werden.

Der Updater verwendet deshalb das unmittelbar vor dem Update erstellte Datenbankbackup für eine vollständige Wiederherstellung, wenn ein automatischer Rollback erforderlich ist.

### Manuelle Wiederherstellung

In der Systemverwaltung soll ein Bereich „Letztes Update wiederherstellen“ vorhanden sein.

Dabei muss deutlich angezeigt werden, auf welchen Stand Anwendung und Datenbank zurückgesetzt werden.

## Neustarts

Ein Update darf benötigte Dienste automatisch neu starten, beispielsweise:

- Apache
- PHP-FPM, falls verwendet
- eigener Setup-/Systemdienst
- cloudflared, falls dessen Konfiguration geändert wurde

Ein kompletter Neustart des Raspberry Pi erfolgt nur, wenn er tatsächlich erforderlich ist. Der Administrator soll vorher darauf hingewiesen werden.

## Betriebssystemupdates

Anwendungsupdates und Raspberry-Pi-OS-Updates sind getrennte Vorgänge.

### Ticketsystem

Über den beschriebenen Update-Service.

### Betriebssystem

Sicherheitsupdates sollen kontrolliert verwaltet werden. Der Expertenbereich zeigt verfügbare Systemupdates und Neustartbedarf an.

Eine spätere Option für automatische Betriebssystem-Sicherheitsupdates kann vorgesehen werden, ist aber nicht identisch mit dem Ticketsystem-Updater.

## Entwicklungs-Shortcut

Während der aktiven Entwicklung darf eine Instanz mit dem Kanal `development` zusätzlich den aktuellen `main`-Stand direkt installieren. Dieser Komfortweg ist klar vom späteren Produktivupdate getrennt.

Eigenschaften:

- nur für `INSTALL_CHANNEL=development`
- nur für System-Admins
- fest verdrahtete Quelle: dieses Repository, Branch `main`
- keine frei wählbare URL und kein frei wählbarer Shellbefehl
- Installation läuft in einem eigenen systemd-Oneshot-Dienst
- der bestehende Installer bleibt die einzige Installationslogik
- normale Migrationen und Health-Checks werden weiterhin ausgeführt

Da `main` veränderlich und nicht als signiertes Release freigegeben ist, wird dieser Weg auf `stable` niemals angeboten. Produktive Schulen erhalten ausschließlich den signierten Releaseprozess.

## Releaseprozess für den Maintainer

Geplanter Ablauf beim Veröffentlichen:

1. Version festlegen.
2. Tests ausführen.
3. Releasepaket erzeugen.
4. Manifest erzeugen.
5. SHA256 berechnen.
6. Manifest mit privatem Release-Schlüssel signieren.
7. GitHub Release erstellen.
8. Assets hochladen.
9. Release Notes veröffentlichen.
10. erst danach Release als stabile Version freigeben.

Die Clients erkennen das Release beim nächsten Prüfintervall automatisch.

## Benutzererlebnis

Im Einfach-Modus soll ein Update nicht technischer wirken als ein normales Softwareupdate:

> **Version 1.4.2 ist verfügbar**
>
> Dieses Update behebt Fehler und verbessert die Stabilität.
>
> Vor der Installation wird automatisch eine Sicherung erstellt.
>
> [Später] [Änderungen anzeigen] [Update installieren]

Nach Bestätigung:

```text
Update wird vorbereitet
✓ System geprüft
✓ Backup erstellt
✓ Paket heruntergeladen
✓ Signatur geprüft
✓ Datenbank aktualisiert
✓ Anwendung aktualisiert
✓ Funktionstest erfolgreich

Version 1.4.2 ist jetzt installiert.
```

Der Expertenmodus darf die vollständigen technischen Schritte und Protokolle anzeigen.
