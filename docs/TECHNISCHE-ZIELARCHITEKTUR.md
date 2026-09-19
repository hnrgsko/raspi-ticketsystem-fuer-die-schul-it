# Technische Zielarchitektur v1

Stand: 19.09.2026

Dieses Dokument hält die verbindlichen technischen Grundentscheidungen für die erste portable Raspberry-Pi-Version fest.

## 1. Referenzhardware

Die Referenzplattform ist bewusst konservativ gewählt:

- Raspberry Pi 3
- microSD-Karte als primäres Systemlaufwerk
- Raspberry Pi OS 64-bit
- Raspberry Pi OS Lite und Desktop werden unterstützt
- kabelgebundenes Ethernet wird empfohlen, WLAN bleibt möglich

Wenn die Anwendung auf einem Pi 3 zuverlässig funktioniert, soll sie ohne Sonderpfad auch auf Pi 4 und leistungsfähigeren Modellen laufen.

Der Installer darf trotzdem Architektur, RAM, freien Speicher und OS-Version prüfen und Warnungen ausgeben.

## 2. Dateisystemlayout

Die Struktur orientiert sich am Filesystem Hierarchy Standard (FHS) und an üblichen selbstgehosteten Linux-Projekten.

### Anwendungscode

```text
/opt/schulit/
├── current -> releases/1.0.0
├── releases/
│   └── 1.0.0/
└── updater/
```

`/opt` enthält die statische Zusatzsoftware und versionierte Releases.

### Konfiguration

```text
/etc/schulit/
├── app.php
├── system.conf
├── release-key.pub
├── backup.conf
└── tunnel.conf
```

Secrets werden mit restriktiven Dateirechten gespeichert und niemals in das Git-Repository übernommen.

### Veränderliche Zustände

```text
/var/lib/schulit/
├── uploads/
├── sessions/
├── admin-sessions/
├── setup/
├── update-state/
├── migrations/
└── recovery/
```

### Cache und temporäre Daten

```text
/var/cache/schulit/
/run/schulit/
```

### Backups

Lokale Notfall-/Zwischensicherungen:

```text
/var/backups/schulit/
```

Dauerhafte Standardsicherung auf USB:

```text
/mnt/schulit-backup/
```

### Logs

Systemdienste schreiben primär in das systemd-Journal. Anwendungslogs mit dauerhaftem Nutzen können zusätzlich unter `/var/log/schulit/` geführt werden.

## 3. Webserver und Datenbank

Vorgesehen:

- Apache
- PHP
- MariaDB
- MariaDB nur lokal gebunden
- keine öffentliche Datenbankfreigabe
- öffentlicher Webzugriff ausschließlich über Apache und den konfigurierten Tunnel bzw. die Experten-Netzwerkvariante

Die konkrete PHP-Version richtet sich an der jeweils unterstützten Raspberry-Pi-OS-Version aus. Der Installer prüft die installierbaren Pakete statt eine unnötig starre Fremdquelle vorauszusetzen.

## 4. Dienste

Geplante systemd-Dienste:

- `schulit-updater.service`
- `schulit-update-check.timer`
- `schulit-backup.service`
- `schulit-backup.timer`
- `schulit-health.service`
- `schulit-health.timer`

Zusätzlich:

- Apache
- MariaDB
- cloudflared, wenn Cloudflare aktiviert ist

## 5. Rollen

### System-Administrator

Darf alles, was ein Ticket-Administrator darf, zusätzlich:

- Updates installieren
- Backups konfigurieren und wiederherstellen
- Domain/Tunnel verwalten
- Systemzustand und technische Logs einsehen
- Recovery verwalten
- technische Einstellungen ändern
- Dienste kontrolliert neu starten

### Ticket-Administrator

Darf die fachliche Ticketanwendung verwalten, insbesondere:

- Tickets
- Status/Priorität
- interne Kommentare
- Wissensdatenbank
- Kategorien
- fachliche Statistik
- weitere fachliche Einstellungen

Darf keine Systemupdates, Backups, Tunnel- oder Servereinstellungen verändern.

## 6. USB-Backup

USB-Backup ist für v1 vorgesehen und wird im Setup vorbereitet.

### Einrichtung

Der Assistent:

1. erkennt angeschlossene USB-Datenträger,
2. zeigt Hersteller/Modell/Größe/Dateisystem verständlich an,
3. lässt den System-Administrator bewusst einen Datenträger auswählen,
4. speichert die UUID des gewählten Dateisystems,
5. richtet einen festen Mountpunkt `/mnt/schulit-backup` ein,
6. verwendet `nofail`, damit der Raspberry Pi auch ohne eingesteckten Stick bootet.

Der Installer formatiert einen Datenträger niemals ohne ausdrückliche, mehrstufige Bestätigung.

### Inhalt eines Vollbackups

- MariaDB-Dump
- `/etc/schulit/`
- Uploads
- anwendungsrelevante persistente Daten
- Migrationsstand
- installierte Versionsinformation
- notwendige Metadaten für Wiederherstellung

Backups werden in ein transportables Archiv gepackt. Eine Verschlüsselung der Sicherungen ist für personenbezogene Schuldaten vorzusehen.

### Routine

Vorgeschlagener Standard:

- täglich ein automatisches Backup, wenn der registrierte USB-Stick vorhanden ist
- zusätzlich vor jedem Anwendungupdate
- Aufbewahrungsrotation mit mehreren Generationen
- Warnung im Adminbereich, wenn mehrere geplante Backups hintereinander fehlschlagen oder der Stick fehlt

Die endgültigen Aufbewahrungswerte werden beim Implementieren festgelegt und im Expertenmodus konfigurierbar gemacht.

## 7. Wiederherstellung auf einem neuen Raspberry Pi

Der Installer bietet bereits während des Onboardings:

> Neue Installation  
> Bestehende Installation aus Sicherung wiederherstellen

Ablauf:

1. frisches Raspberry Pi OS installieren
2. Installer starten
3. USB-Stick einstecken oder Backup-Datei auswählen
4. Backup prüfen
5. enthaltene Version und Metadaten anzeigen
6. Wiederherstellung bestätigen
7. Anwendung, Datenbank, Konfiguration und Uploads wiederherstellen
8. notwendige Systempakete neu installieren
9. Tunnel-/Domainzustand prüfen und falls nötig neu autorisieren
10. Health-Check durchführen

Das Ziel ist, dass kein Linux-Fachwissen für einen Hardwaretausch erforderlich ist.

## 8. Recovery-Code

Die Schulnummer darf nicht als Passwort oder alleiniger Recovery-Schlüssel verwendet werden, weil sie nicht ausreichend geheim bzw. zufällig ist.

Sie wird stattdessen als gut erkennbare Identitätskomponente eingebaut.

Beispiel:

```text
123456-7K9P-4X2M-Q8R6-W3NZ
```

Dabei ist:

- `123456` die konfigurierte Schulnummer bzw. Schulkennung,
- der restliche Teil kryptografisch zufällig.

Gespeichert wird nur ein sicherer Prüfwert des geheimen Recovery-Anteils.

Der Assistent fordert nach der Installation dazu auf:

- Recovery-Code auszudrucken,
- als PDF lokal zu speichern oder
- anderweitig sicher außerhalb des Raspberry Pi aufzubewahren.

Der Code kann für kontrollierte Wiederherstellungs- bzw. System-Admin-Reset-Prozesse verwendet werden.

## 9. Cloudflare-Onboarding

Mit „Cloudflare-Onboarding festlegen“ ist gemeint, den genauen Authentifizierungsweg zu definieren, damit Nutzer nicht API-Tokens kopieren und Dashboard-Menüs durchsuchen müssen.

Ziel für den Einfach-Modus:

> Cloudflare verbinden

Danach öffnet sich einmal die Cloudflare-Anmeldung bzw. Zustimmungsseite. Der Nutzer meldet sich an, wählt sein Konto und erlaubt nur die benötigten Berechtigungen. Anschließend kehrt er in unseren Setup-Assistenten zurück.

Für eine offene, installierbare Anwendung darf kein fest eingebettetes OAuth-Client-Secret verwendet werden.

Die bevorzugte Architektur ist deshalb:

- Cloudflare Public OAuth Application
- Authorization Code Flow mit PKCE
- kein Client-Secret auf dem Raspberry Pi
- möglichst eng begrenzte Scopes
- Token verschlüsselt bzw. rootgeschützt auf dem Pi speichern
- Berechtigung später über Cloudflare widerrufbar

Fallback für frühe Entwicklung:

- `cloudflared tunnel login` mit Browseranmeldung

Dieser Fallback soll später nicht der primäre Einsteigerweg sein.

## 10. Domainpfade

Der Assistent behandelt mindestens:

1. bestehende Domain bereits bei Cloudflare
2. bestehende Domain bei anderem DNS-Anbieter
3. gewünschte Subdomain einer bestehenden Schulhomepage
4. noch keine Domain vorhanden
5. temporärer Testbetrieb

Der Assistent erklärt jeweils, welche Schritte extern tatsächlich notwendig sind.

## 11. Schulneutralisierung

Das bestehende Ticketsystem ist nur Referenz.

Schulspezifische Bestandteile werden in vier Gruppen eingeteilt:

### Immer konfigurierbar

- Schulname
- Schulnummer/Schulkennung
- Logo
- Farben
- Impressumsangaben
- Datenschutzangaben
- Domain
- Administratoren

### Standardmäßig vorhanden, aber anpassbar

- Kategorien
- Hilfetexte
- Prioritäten
- fachliche Bezeichnungen

### Optionales Modul

- AIS/gsKI
- externe Supportweiterleitung

### Instanzspezifisch und nicht Teil des Standards

- HORN & COSIFAN
- konkrete GSK-Namen, Kürzel, Logos und URLs
- konkrete Plesk-/Netcup-Konfiguration

HORN & COSIFAN wird deshalb nicht als feste Kernfunktion übernommen. Die generische Architektur kann später externe Supportziele als optionales Modul erlauben.

## 12. Updatearchitektur

Der Updateprozess orientiert sich an etablierten selbstgehosteten Projekten:

- Clients erkennen neue Versionen über veröffentlichte Releases.
- GitHub Releases dienen als Distributionskanal.
- Updates werden erst nach Bestätigung durch einen System-Administrator installiert.
- Vor jedem Update wird automatisch ein Backup erstellt.
- Releaseartefakte werden vor Installation kryptografisch geprüft.
- Versionierte Release-Verzeichnisse ermöglichen atomisches Umschalten.
- Datenbankmigrationen werden separat historisiert.
- Rollback wird explizit berücksichtigt.

Für v1 werden TUF-Sicherheitsprinzipien übernommen, ohne sofort die vollständige TUF-Infrastruktur einzuführen:

- signierte Metadaten
- Hashprüfung
- Schutz vor versehentlichem Downgrade
- getrennte Release- und Clientlogik
- vorbereitete Schlüsselrotation

Später kann auf eine vollständige TUF-Implementierung migriert werden, falls das Projekt oder die Nutzerzahl dies rechtfertigt.

## 13. Systemzustand im Adminbereich

Der normale Adminbereich zeigt Systemwarnungen an, die für den Betrieb relevant sind:

- Update verfügbar
- Backup fehlgeschlagen oder zu alt
- USB-Backupmedium fehlt
- Speicher knapp
- Tunnel offline
- Domain nicht erreichbar
- Datenbankproblem
- Neustart erforderlich

System-Administratoren können direkt zur Systemverwaltung wechseln.

Ticket-Administratoren sehen nur die Hinweise, die für ihre Arbeit sinnvoll sind; Aktionen mit Systemrechten bleiben gesperrt.

## 14. Landingpage und Installer-Verteilung

Geplant:

- GitHub Pages als öffentliche Landingpage/Dokumentation
- GitHub Releases für versionierte Installerpakete
- direkter Download für Raspberry Pi OS Desktop
- Ein-Befehl-Bootstrap für Lite/SSH
- SHA256 und Signaturprüfung
- Anfängeranleitungen ab Hardwarevorbereitung
- Domain-/Tunnel-/Datenschutz-Erklärungen
- Expertenbereich

## 15. Referenztests

Da Pi 3 die Referenzplattform ist, werden Funktion und Performance dort zuerst geprüft.

Mindestens zu testen:

- frische Installation Pi 3 + microSD
- Desktop-Installationsweg
- Lite/SSH-Installationsweg
- USB-Backup
- Wiederherstellung auf frischer Installation
- Update zwischen zwei Releases
- fehlgeschlagenes Update
- Rollback
- verlorene/defekte Backupmedien
- Tunnelunterbrechung
- Neustart nach Stromausfall

Pi 4 wird zusätzlich als Kompatibilitätscheck genutzt, aber nicht als Mindestplattform.
