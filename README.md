# RasPi-Ticketsystem für die Schul-IT

Ein möglichst niedrigschwellig installierbares, selbst gehostetes Schul-IT-Ticketsystem für Raspberry Pi.

## Ziel

Eine Schule soll keinen klassischen Webhosting-Vertrag, kein Plesk und keinen externen MySQL-Hoster benötigen. Das komplette Ticketsystem läuft auf einem Raspberry Pi im Schulnetz oder in einem anderen geeigneten Netzwerk.

Der gewünschte Standardablauf ist:

1. Raspberry Pi OS installieren.
2. Raspberry Pi mit Netzwerk und Strom verbinden.
3. Installer über einen Download oder einen einzigen Konsolenbefehl starten.
4. Die restliche Einrichtung in einem geführten Web-Assistenten durchführen.
5. Das Ticketsystem über eine schulindividuelle Domain oder Subdomain sicher von außen erreichbar machen.
6. Spätere Programmupdates direkt aus der Systemverwaltung installieren.

Für erfahrene Administratoren bleiben erweiterte Einstellungen zugänglich. Für Einsteiger soll die Standardinstallation so wenig Fachwissen wie möglich voraussetzen.

## Zwei Bedienebenen

### Einfach-Modus

Der Standardweg erklärt jeden Schritt verständlich und setzt sinnvolle Standardwerte. Fachbegriffe wie DNS, Reverse Proxy, Datenbankmigration oder systemd sollen nicht vorausgesetzt werden.

### Experten-Modus

Erweiterte Einstellungen für Netzwerk, Webserver, Datenbank, Tunnel, Backups, Updates, Hostnamen, Diagnose und weitere technische Parameter.

## Geplante Installation

### Grafischer Weg

Für Raspberry Pi OS mit Desktop soll die Projekt-Landingpage einen direkt herunterladbaren Installer anbieten. Nach der technischen Grundinstallation wird die Einrichtung im Browser fortgesetzt.

### Konsole / SSH

Für Raspberry Pi OS Lite, Headless-Installationen und erfahrene Nutzer soll ein einzelner Bootstrap-Befehl angeboten werden.

## Öffentliche Erreichbarkeit

Standard soll ein ausgehender Tunnel sein, damit normalerweise keine Portfreigabe am Schul- oder Heimrouter erforderlich ist.

Geplante Modi:

- Cloudflare Tunnel als geführter Standardweg
- temporärer Testzugang ohne eigene Domain, sofern der verwendete Tunnelanbieter dies unterstützt
- direkter Internetzugang mit eigener Firewall-/Port-Konfiguration als Expertenoption
- Architektur offen halten für alternative Tunnelanbieter

Die Tunnel- und Domainkonfiguration soll möglichst weitgehend im eigenen Setup-Assistenten erfolgen.

## Domain

Der Assistent soll drei Ausgangslagen verständlich behandeln:

- Schule besitzt bereits eine geeignete Domain und kann deren DNS verwalten.
- Schule möchte eine Subdomain ihrer bestehenden Schulhomepage verwenden.
- Schule besitzt noch keine Domain und benötigt eine verständliche Anleitung zur Registrierung.

Eine vorhandene Schulhomepage muss nicht auf dem Raspberry Pi liegen. Entscheidend ist nur, dass die notwendige DNS-Konfiguration für die gewünschte Ticket-Adresse möglich ist.

## Netzwerkstandort

Der Raspberry Pi kann technisch sowohl im Schulnetz als auch in einem geeigneten Heimnetz stehen. Der öffentliche Zugriff erfolgt unabhängig vom Standort über die konfigurierte Verbindung.

Für den produktiven Schulbetrieb müssen zusätzlich organisatorische Anforderungen wie physischer Schutz, Stromversorgung, Internetverfügbarkeit, Backups und Datenschutz berücksichtigt werden.

## Landingpage

Die öffentliche Projektseite ist Bestandteil des Produkts. Sie soll bei Null beginnen und unter anderem erklären:

- welches Raspberry-Pi-Modell geeignet ist,
- welche Stromversorgung benötigt wird,
- welche Speicherkarte bzw. welches Laufwerk benötigt wird,
- wann ein Kartenleser erforderlich ist,
- wie Raspberry Pi OS installiert wird,
- wie Netzwerk und SSH eingerichtet werden,
- wie der Installer gestartet wird,
- was eine Domain und eine Subdomain sind,
- wozu ein Tunnel dient,
- welche Schritte bei Cloudflare erforderlich sind,
- welche Datenschutzfragen vor dem Produktivbetrieb geprüft werden müssen.

Die Landingpage soll unterschiedliche Einstiege anbieten, z. B. „Ich habe noch kein Raspberry Pi OS installiert“, „Ich sitze direkt am Raspberry Pi“ und „Ich verwalte den Pi per SSH“.

## Update-Service

Jede installierte Instanz soll selbstständig auf neue stabile Releases prüfen.

Wenn eine neue Version verfügbar ist, erscheint in der Ticket-Administration und der Systemverwaltung eine Meldung:

> **Eine neue Version ist verfügbar. Möchten Sie das Update installieren?**

Der Administrator kann Versionshinweise ansehen, das Update installieren oder die Meldung zunächst schließen.

Nach Bestätigung soll die Instanz das freigegebene Update automatisch herunterladen, kryptografisch prüfen, ein Backup erstellen, Datenbankmigrationen anwenden, die Anwendung aktualisieren und anschließend einen Systemtest durchführen.

Updates werden nicht allein deshalb installiert, weil sie verfügbar sind. Eine automatische Installation ohne Bestätigung ist im Standardmodus nicht vorgesehen.

Das genaue Sicherheits- und Rollbackkonzept ist in [docs/UPDATE-SERVICE.md](docs/UPDATE-SERVICE.md) dokumentiert.

## Systemverwaltung

Neben dem eigentlichen Ticket-Adminbereich ist eine separate Systemverwaltung vorgesehen. Dort sollen Administratoren später unter anderem sehen bzw. steuern können:

- Systemzustand
- Webserver
- Datenbank
- Tunnel
- Domain
- Speicherplatz
- installierte Version
- verfügbare Updates
- Updateverlauf
- Backups und Wiederherstellung
- Diagnose
- Logs
- Netzwerkparameter

Diese Systemverwaltung soll standardmäßig nicht unnötig öffentlich exponiert werden.

## Referenzprojekt

Das bestehende Repository `hnrgsko/ticketsystem-schul-it` dient ausschließlich als Read-only-Referenz für Funktionen, Datenmodell, Oberfläche und technische Bausteine.

Es wird im Rahmen dieses Projekts nicht verändert.

Die schulneutrale, portable Raspberry-Pi-Version wird ausschließlich in diesem Repository entwickelt.

## Aktueller Entwicklungsstand

### Phase 1 – auf Raspberry Pi 4 erfolgreich real getestet

Bestätigt auf echter Hardware:

- Raspberry Pi OS 64-bit auf microSD
- Apache/PHP/MariaDB-Installation
- vorhandener Port-80-Dienst wird nicht verdrängt
- lokaler Setup-Port 8080
- token-geschützter Browserzugang
- automatische Health-Checks

Der Raspberry Pi 3 bleibt die geplante Mindest-/Referenzplattform und wird später separat gegengeprüft.

### Phase 2 – auf Raspberry Pi 4 erfolgreich real getestet

Im Repository vorhanden:

- Auswahl „Neue Installation“ / „Aus Backup wiederherstellen“
- Eingabe von Schulname und Schulnummer/Schulkennung
- erster System-Administrator
- Rollenbasis `system_admin` und `ticket_admin`
- lokale MariaDB-Anwendungsdatenbank
- eigener lokaler privilegierter `schulit-setupd`-Dienst über Unix-Socket
- Recovery-Code mit Schulkennung + kryptografischem Zufallsanteil
- Speicherung nur eines scrypt-Prüfwerts des Recovery-Codes
- Setup-CSRF-Schutz und serverseitige Validierung

Auf echter Hardware erfolgreich bestätigt: Schuldaten, erster System-Administrator, lokale Datenbank, Recovery-Code und Abschlussstatus. Die echte Backup-Wiederherstellung ist im Assistenten bereits vorgesehen und wird in Phase 3 mit dem USB-Backupformat aktiviert.

Dokumentation:

- [Phase 1 – Bootstrap und lokale Serverbasis](docs/PHASE-1-BOOTSTRAP.md)
- [Phase 2 – Einrichtungsassistent](docs/PHASE-2-SETUP.md)
- [Phase 3a – USB-Backupmedium registrieren](docs/PHASE-3-USB-BACKUP.md)
- [Phase 3b – Verschlüsselte Backups](docs/PHASE-3B-ENCRYPTED-BACKUP.md)
- [Phase 3c – Wiederherstellung](docs/PHASE-3C-RESTORE.md)
- [Phase 4a – Lokales Ticketsystem](docs/PHASE-4A-LOCAL-TICKETS.md)

### Phase 3a – auf Raspberry Pi 4 erfolgreich real getestet

Neu im Setup-Assistenten:

- USB-Datenträger automatisch erkennen
- Label, Modell, Größe, Dateisystem, UUID und Mountpunkt anzeigen
- Backupmedium bewusst auswählen
- ausschließlich UUID dauerhaft speichern
- vorhandene Daten vollständig erhalten
- keinen Datenträger formatieren oder leeren
- ausschließlich `SchulIT-Ticketsystem/Backups/<Schulkennung>/` anlegen
- fremde gleichnamige Ordner sicher erkennen und nicht überschreiben
- schreibgeschützte bzw. nicht unterstützte Datenträger nicht auswählbar machen

Auf echter Hardware erfolgreich bestätigt: USB-Erkennung, Auswahl per UUID und nicht-destruktives Anlegen von `SchulIT-Ticketsystem/Backups/<Schulkennung>/` auf einem vorhandenen USB-Datenträger.

Dokumentation: [Phase 3a – USB-Backupmedium registrieren](docs/PHASE-3-USB-BACKUP.md)

### Phase 3b – auf Raspberry Pi 4 erfolgreich real getestet

Neu vorhanden:

- Verschlüsselung mit `age`
- Recovery-Code-geschützter privater Entschlüsselungsschlüssel
- verschlüsseltes Vollbackup von Datenbank, Konfiguration, Status und Uploads
- SHA-256-Manifest pro Backup
- Button „Backup jetzt erstellen“
- täglicher systemd-Backup-Timer
- Timer wird erst nach erfolgreicher Verschlüsselung aktiviert

Auf echter Hardware erfolgreich bestätigt: Recovery-Code-geschützte Backup-Verschlüsselung, Erzeugung eines `.tar.gz.age`-Archivs, Manifest/Statusanzeige und aktivierter täglicher Backup-Timer.

Dokumentation: [Phase 3b – Verschlüsselte Backups](docs/PHASE-3B-ENCRYPTED-BACKUP.md)

### Phase 3c – Restore-Prüfung auf Raspberry Pi 4 erfolgreich real getestet

Neu vorhanden:

- frische Installation kann USB-Backups ohne vorhandene Schulkonfiguration finden
- Auswahl vorhandener Backupstände
- SHA-256-Prüfung vor der Wiederherstellung
- Recovery-Code-basierte Entschlüsselung des privaten age-Schlüssels
- sichere Entschlüsselung und Archivprüfung
- Wiederherstellung von Datenbank, Schulkonfiguration, Adminstatus, Backupkonfiguration und Uploads
- erneute Aktivierung des automatischen Backup-Timers
- Schutz gegen versehentliches Überschreiben einer bereits eingerichteten Installation

Nicht-destruktiver Restore-Test auf echter Hardware erfolgreich bestätigt: SHA-256, Recovery-Code, age-Entschlüsselung, sichere Archivprüfung, Konfiguration und Datenbankdump. Das laufende System wurde dabei nicht verändert. Der vollständige Katastrophentest auf einem frischen Boot-Datenträger bleibt noch offen.

Dokumentation: [Phase 3c – Wiederherstellung](docs/PHASE-3C-RESTORE.md)

### Phase 4a – implementiert, nächster Realtest

Neu vorhanden:

- schulneutrales Ticketdatenmodell
- lokale Kollegiumsseite auf Port `8081`
- geheimer Zugangslink mit Zufallstoken
- Token verschwindet nach erfolgreichem Einstieg aus der URL
- Support- und Defekt-Ticketformular
- Name **und** Kürzel, keine E-Mail
- Ticketnummer + Statusabfrage über Schulkennung
- lokaler Ticket-Admin unter `/admin/`
- Rollenbasis `system_admin` / `ticket_admin`
- Status, Priorität, interne Notizen und Archiv
- ausstehende Datenbankmigrationen bei Installer-Updates
- Kollegiums-Zugangstoken wird verschlüsselt mitgesichert

Ticketanlage, Statusabfrage, Admin-Login, Statusänderung und interne Notizen auf echter Hardware erfolgreich bestätigt. Offen für den vollständigen Phase-4a-Test ist nur noch der Archiv-Workflow.

Dokumentation: [Phase 4a – Lokales Ticketsystem](docs/PHASE-4A-LOCAL-TICKETS.md)

**Noch nicht für produktive Schuldaten verwenden.**
Siehe auch:

- [Produkt- und Installationskonzept](docs/PRODUKTKONZEPT.md)
- [Update-Service](docs/UPDATE-SERVICE.md)
- [Technische Zielarchitektur v1](docs/TECHNISCHE-ZIELARCHITEKTUR.md)


### Phase 4a.1 – UI-Parität und optionaler KI-Assistent

Implementiert, nächster Realtest:

- GSK-nahe Admin-Ticketübersicht
- Suche, Filter und Sortierung
- Schnellaktionen direkt in der Ticketliste
- responsive mobile Kartenansicht
- sichtbares Archiv
- optionaler schulindividueller KI-Assistent
- bei Aktivierung als empfohlener erster Schritt vor der Ticketabgabe
- experimentelle schwebende Sprechblase für HTTPS-Assistenten; bisher mit AIS.chat-Dialogpartnern getestet

Dokumentation: [Phase 4a.1 – UI-Parität und optionaler KI-Assistent](docs/PHASE-4A1-UI-PARITY.md)


### Phase 4b – Automatisiertes FAQ mit Moderation

Implementiert, nächster Realtest:

- automatische FAQ-Entwürfe beim erstmaligen Status **Erledigt**
- regelbasierte Problemfrage aus dem Ticket
- letzte interne Notiz optional als zu prüfender Antwortentwurf
- keine automatische Veröffentlichung
- Moderationsbereich **Admin → FAQ**
- direkte FAQ-Fragen durch Admins
- FAQ entstehen aus normalen Support-Tickets des Kollegiums
- veröffentlichte FAQ auf der Kollegiumsseite
- FAQ ausblenden und wieder veröffentlichen
- Automatik durch System-Admins abschaltbar

Dokumentation: [Phase 4b – Automatisiertes FAQ mit Moderation](docs/PHASE-4B-AUTOMATED-FAQ.md)


### Phase 4c – Nutzungsstatistik

Implementiert, nächster Realtest:

- Adminbereich **Statistik**
- Tickets heute / 7 Tage / 30 Tage / gesamt
- offene und erledigte Tickets
- Assistent-Nutzung getrennt nach:
  - direkt eingebettetem iframe
  - Sprechblase
  - externem Aufruf
- anonyme Sitzungszählung je Zugangsweg
- **Tickets nach vorheriger Assistent-Nutzung**
- 30-Tage-Verlauf
- ausschließlich aggregierte Tageszähler; keine IP-Adressen, Namen oder Chat-Inhalte

Dokumentation: [Phase 4c – Nutzungsstatistik](docs/PHASE-4C-STATISTICS.md)


### Phase 4d – FAQ-Autopilot

Implementiert, nächster Realtest:

- automatische Bewertung neuer FAQ-Entwürfe
- lokale Dubletten-/Ähnlichkeitserkennung
- Empfehlungen:
  - neue FAQ sinnvoll
  - bestehende FAQ wahrscheinlich
  - bitte kurz prüfen
- bestehende FAQ direkt aus neuem Supportfall aktualisieren
- Ticket-zu-FAQ-Verknüpfungen
- Häufigkeitszähler realer Supportfälle je FAQ
- keine externe KI für die Erkennung notwendig
- weiterhin keine automatische Veröffentlichung ohne Moderation

Dokumentation: [Phase 4d – FAQ-Autopilot](docs/PHASE-4D-FAQ-AUTOPILOT.md)


### Phase 5a – Öffentlicher Zugang mit Cloudflare Tunnel

Implementiert, nächster Realtest:

- geführte Einrichtung unter **Admin → System**
- schulindividueller Hostname / Subdomain
- Cloudflare Tunnel ohne Router-Portfreigabe
- Tunnel-Token oder kompletter Cloudflare-Installationsbefehl kann eingefügt werden
- automatische `cloudflared`-Installation für ARM, ARM64 und amd64
- eigener gehärteter systemd-Dienst
- Token root-only und nicht erneut in der Oberfläche sichtbar
- öffentlicher Kollegiumslink mit bestehendem Zugangstoken
- separater Verbindungstest
- Tunnel kann lokal wieder getrennt werden
- Installer prüft bei konfiguriertem Tunnel Rechte und Dienststatus

Dokumentation: [Phase 5a – Öffentlicher Zugang](docs/PHASE-5A-PUBLIC-ACCESS.md)


### Phase 5b – Administratorkonten

Implementiert, nächster Realtest:

- mehrere Admin-Konten ohne Datenbankzugriff
- Rollen `system_admin` und `ticket_admin`
- eigenes Passwort ändern
- mindestens 14 Zeichen
- Startpasswort für neue Konten
- erzwungener Passwortwechsel beim ersten Login
- Konten aktivieren / sperren
- Rollen anderer Konten ändern
- Startpasswort anderer Konten zurücksetzen
- Schutz des letzten aktiven System-Admins

Dokumentation: [Phase 5b – Administratorkonten](docs/PHASE-5B-ADMIN-ACCOUNTS.md)


### Phase 5c – Updateprüfung

Implementiert, nächster Realtest:

- automatische Releaseprüfung alle sechs Stunden
- manuelle Prüfung im Systembereich
- Update-Banner im Adminbereich
- Release Notes
- keine Telemetrie
- noch keine automatische Installation ohne signierte Releases

Dokumentation: [Phase 5c – Updateprüfung](docs/PHASE-5C-UPDATE-CHECKER.md)

### Phase 5d – Backupverwaltung

Implementiert, nächster Realtest:

- Backupstatus unter **Admin → System**
- USB-Medium / Verschlüsselung / letzter Stand
- manuelles verschlüsseltes Backup
- Restore-Rechte gehärtet
- ältere Backups ohne Zugangstoken bleiben kompatibel

Dokumentation: [Phase 5d – Backupverwaltung](docs/PHASE-5D-BACKUP-ADMIN.md)

### Phase 5e – Sicherer Ticketstatus

Auf Raspberry Pi 4 erfolgreich real getestet:

- kurzer zufälliger Statuscode mit acht gut lesbaren Zeichen pro Ticket
- Statusabfrage mit Ticketnummer + Statuscode
- Klartextcode nur einmal sichtbar
- Datenbank speichert nur SHA-256
- Admin kann neuen Statuscode erzeugen
- alte unsichere Statusabfrage entfällt

Dokumentation: [Phase 5e – Sicherer Ticketstatus](docs/PHASE-5E-TICKET-STATUS-CODE.md)


### Phase 5f – FAQ-Self-Service vor dem Ticket

Implementiert, nächster Realtest:

- lokale FAQ-Suche während der Supportmeldung
- bis zu drei passende veröffentlichte Lösungen
- berücksichtigt Kategorie, Gerät und Problemtext
- reale Ticket-Häufigkeit fließt leicht in die Gewichtung ein
- „Das hat geholfen – kein Ticket nötig“
- keine externe Übertragung der Problembeschreibung
- Statistik für FAQ-Nutzung und Tickets trotz vorheriger FAQ

Dokumentation: [Phase 5f – FAQ-Self-Service](docs/PHASE-5F-FAQ-SELF-SERVICE.md)


## Gesamttest 0.5

Für den ersten zusammenhängenden Hardwaretest nach den Phasen 4b–5f gibt es einen vollständigen Ablaufplan:

[Gesamttest Version 0.5 – Raspberry Pi](docs/TESTPLAN-0.5.md)

Bei einem Fehler kann nach dem Update das datensparsame Diagnosekommando verwendet werden:

```bash
sudo schulit-diagnostics
```

Es gibt keine Passwörter, Access-Tokens, Recovery-Codes, Tunnel-Tokens oder Ticketinhalte aus.
