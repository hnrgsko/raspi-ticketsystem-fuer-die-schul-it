# Produkt- und Installationskonzept

## 1. Leitidee

Das Projekt soll ein vollständiges, selbst gehostetes Schul-IT-Ticketsystem auf einem Raspberry Pi bereitstellen.

Der Raspberry Pi übernimmt dabei die Rolle eines kleinen Servers:

- Webserver
- PHP-Laufzeit
- MariaDB
- Ticketsystem
- lokale Konfiguration
- Uploadspeicher
- Sitzungen
- Backup-Grundlage
- Update- und Diagnosefunktionen
- ausgehende Tunnelverbindung für den öffentlichen Zugriff

Ein externer Webhoster wie Netcup ist für die Raspberry-Pi-Version nicht erforderlich.

Die Bedienung folgt dem Prinzip:

> Einfach für Einsteiger, vollständig kontrollierbar für Administratoren.

## 2. Zielgruppen

### Einsteiger

Personen, die einen Raspberry Pi grundsätzlich bedienen können, aber keine Erfahrung mit Linux-Servern, Apache, PHP, MariaDB, DNS oder Tunneln haben.

Sie sollen mit möglichst wenigen Entscheidungen zu einem sicheren Standard-Setup geführt werden.

### Erfahrene Administratoren

Personen, die Netzwerk, DNS, Webserver und Linux selbst konfigurieren möchten.

Sie erhalten Zugriff auf erweiterte Einstellungen, ohne dass diese den normalen Installationsweg komplizierter machen.

## 3. Nutzerreise vor der Installation

Die öffentliche Landingpage soll nicht erst beim Installer beginnen, sondern bei den tatsächlichen Voraussetzungen.

### Einstieg A: Noch nichts vorbereitet

Die Seite erklärt:

1. Raspberry Pi auswählen.
2. Netzteil bereitstellen.
3. microSD-Karte oder anderes Bootmedium bereitstellen.
4. Falls nötig Kartenleser verwenden.
5. Raspberry Pi Imager auf einem Windows-, macOS- oder Linux-Rechner installieren.
6. Raspberry Pi OS schreiben.
7. Benutzer, Netzwerk und optional SSH bereits im Imager konfigurieren.
8. Bootmedium in den Raspberry Pi einsetzen.
9. Netzwerk anschließen.
10. Raspberry Pi starten.
11. Installer starten.

### Einstieg B: Raspberry Pi OS Desktop läuft bereits

Direkter Download eines grafischen Installationspakets.

Ziel: Download anklicken, Installation bestätigen, anschließend Setup-Assistent im Browser.

### Einstieg C: Raspberry Pi OS Lite / SSH

Ein kompakter Bootstrap-Befehl installiert die technische Grundlage und startet anschließend den lokalen Setup-Assistenten.

## 4. Unterstützte Raspberry-Pi-Plattform

Primäres Ziel:

- Raspberry Pi 4
- Raspberry Pi 3 als unterstützte, leistungsschwächere Variante
- Raspberry Pi OS 64-bit
- zunächst Raspberry Pi OS auf Debian-Basis

Raspberry Pi OS Desktop und Lite sollen unterstützt werden.

Für Serverbetrieb soll kabelgebundenes Ethernet empfohlen werden, WLAN aber nicht grundsätzlich ausgeschlossen sein.

Für dauerhaften Produktivbetrieb soll robuster Speicher empfohlen werden. microSD bleibt als einfacher Einstieg möglich; für höhere Zuverlässigkeit kann ein SSD-basiertes Setup empfohlen werden.

## 5. Installer-Stufen

### Bootstrap

Der Bootstrap erledigt nur die technische Vorbereitung:

- Betriebssystem prüfen
- Architektur prüfen
- Internetverbindung prüfen
- Paketquellen aktualisieren
- benötigte Pakete installieren
- Webserver einrichten
- PHP und Module installieren
- MariaDB installieren
- Anwendung bereitstellen
- Setup-Dienst starten
- sichere lokale Verzeichnisse erstellen
- Dateirechte setzen

Danach wird im Browser weitergearbeitet.

### Web-Assistent

Der Web-Assistent übernimmt unter anderem:

1. Schulname
2. Logo und Gestaltung
3. ersten Administrator
4. Datenbankinitialisierung
5. Ticket-Grundeinstellungen
6. optionale Funktionen
7. AIS/gsKI-Konfiguration
8. Netzwerk-/Internetmodus
9. Domain
10. Tunnel
11. Datenschutz-Hinweise
12. Prüfung aller Dienste
13. Abschlussbericht

## 6. Einfach-Modus und Experten-Modus

Der Setup-Assistent zeigt standardmäßig nur die notwendigen Entscheidungen.

Ein Schalter „Erweiterte Einstellungen“ macht zusätzliche Optionen sichtbar.

Der Einfach-Modus setzt sichere Standardwerte. Der Experten-Modus darf unter anderem Webserver-Port, Hostname, Datenbankparameter, Tunnelmodus, Updatekanal, Backupziel und Diagnoseeinstellungen zugänglich machen.

## 7. Domain-Assistent

Der Nutzer wird nicht mit einem leeren DNS-Formular konfrontiert, sondern zunächst gefragt:

### „Haben Sie bereits eine Domain für Ihre Schule?“

#### Ja, und ich darf DNS-Einstellungen ändern

Der Assistent erklärt, ob die ganze Domain oder eine Subdomain verwendet wird.

Beispiel:

`tickets.schule-beispiel.de`

#### Ja, aber die Schulhomepage wird von jemand anderem verwaltet

Der Assistent erzeugt eine konkrete Anleitung für den zuständigen Administrator bzw. Dienstleister.

Er zeigt exakt, welche DNS-Änderung benötigt wird und warum.

#### Nein

Der Assistent erklärt verständlich, was eine Domain ist, welche laufenden Kosten entstehen können und worauf beim Anbieter zu achten ist.

Vorgeschlagene Anbieter sollen nur Beispiele sein. Wichtig sind:

- Zugriff auf DNS bzw. Nameserver
- Möglichkeit zur Nutzung externer Nameserver
- klare Verlängerungskosten
- Zugriff der Schule auf das Kundenkonto
- keine Bindung der Domain an ein bestimmtes Webhostingpaket

## 8. Cloudflare Tunnel als Standard

Der Standardweg soll ohne eingehende Portfreigabe funktionieren.

Der Raspberry Pi baut selbst eine ausgehende Verbindung zum Tunnelanbieter auf.

Vorteile für den Einsteigerweg:

- keine öffentliche IPv4-Adresse erforderlich
- keine Portweiterleitung im Router
- keine direkte Exposition des Raspberry Pi
- unabhängig davon, ob der Pi im Schulnetz oder in einem anderen geeigneten Netz steht
- öffentliche HTTPS-Adresse über die Schul-Domain

Der Assistent soll Cloudflare so weit wie technisch und vertraglich möglich integrieren.

Der Nutzer soll Cloudflare nicht manuell konfigurieren müssen, wenn die nötigen Berechtigungen automatisiert autorisiert werden können.

Ein externer Login-/Autorisierungsschritt darf geöffnet werden; danach soll der Nutzer automatisch zum eigenen Assistenten zurückgeführt werden.

## 9. Wichtige Einschränkung bei bestehenden Schul-Domains

Eine Subdomain wie `tickets.schule.de` ist technisch grundsätzlich geeignet.

Für den einfachen Cloudflare-Standardweg muss aber berücksichtigt werden, wie die DNS-Zone der bestehenden Schul-Domain verwaltet wird.

Wenn die Domain bereits über Cloudflare verwaltet wird, ist die Einrichtung besonders einfach.

Wenn die Domain bei einem anderen DNS-Anbieter liegt, kann je nach Cloudflare-Produkt bzw. Tarif nicht nur ein beliebiger CNAME auf einen Tunnel gesetzt werden. Der Assistent muss deshalb den tatsächlichen DNS-Zustand erkennen bzw. abfragen und anschließend den passenden Weg erklären.

Für Schulen ohne passende DNS-Rechte kann eine separate, nur für das Ticketsystem verwendete Domain der deutlich einfachere Weg sein.

## 10. Testbetrieb

Vor dem Kauf oder der endgültigen Einrichtung einer Domain soll ein Testbetrieb möglich sein.

Ziel:

- Ticketsystem lokal testen
- optional temporären öffentlichen Testzugang erzeugen
- erst danach dauerhafte Domain konfigurieren

Ein temporärer Tunnel darf ausdrücklich nicht als dauerhafte Produktivlösung dargestellt werden, wenn der jeweilige Anbieter dies nicht unterstützt.

## 11. Schulnetz oder Heimnetz

Technisch ist der Standort des Raspberry Pi zweitrangig, solange folgende Bedingungen erfüllt sind:

- stabile Internetverbindung
- ausreichende Stromversorgung
- ausgehende Verbindung zum Tunnelanbieter möglich
- Gerät ist physisch geschützt
- Backups sind eingerichtet

Für echten Schulbetrieb unterscheiden sich die organisatorischen Anforderungen jedoch.

Steht der Pi zuhause, müssen Verantwortlichkeit, physischer Zugriff, private Netzwerkinfrastruktur, Ausfallrisiko und Datenschutz besonders geprüft werden.

Der Assistent soll deshalb nicht behaupten, dass jeder beliebige Standort automatisch für personenbezogene Schuldaten geeignet ist.

## 12. Datenschutz und Cloudflare

Die Produktseite darf nicht pauschal behaupten:

> „Cloudflare ist automatisch DSGVO-konform.“

Stattdessen soll sie transparent erklären:

- welche Daten durch Cloudflare technisch verarbeitet werden können,
- dass Cloudflare Vertragsunterlagen zur Auftragsverarbeitung anbietet,
- dass internationale Datenübermittlungen und die eingesetzten Garantien berücksichtigt werden müssen,
- dass die konkrete Schule bzw. ihr Träger die Nutzung datenschutzrechtlich prüfen muss,
- dass gegebenenfalls Datenschutzbeauftragte oder zuständige Stellen einbezogen werden müssen.

Der Installer soll die benötigten Informationen möglichst gut dokumentieren und eine technische Datenschutzübersicht erzeugen können.

## 13. Trennung der Administrationsbereiche

### Ticket-Administration

Fachliche Verwaltung:

- Tickets
- Hilfen
- Kategorien
- Wissensdatenbank
- Statistik
- Benutzer des Ticketsystems

### Systemverwaltung

Technischer Serverbereich:

- Serverzustand
- CPU/RAM
- freier Speicher
- Datenbankstatus
- Webserverstatus
- Tunnelstatus
- Domainstatus
- Updates
- Backups
- Wiederherstellung
- Systemlogs
- Diagnosedatei
- Netzwerk
- Neustart einzelner Dienste

Die Systemverwaltung soll standardmäßig nur lokal bzw. besonders geschützt erreichbar sein.

## 14. Landingpage / Projektwebsite

Die Projektwebsite soll als statische, leicht wartbare Seite aus diesem Repository heraus bereitstellbar sein.

Naheliegende Architektur:

- GitHub Pages für Dokumentation und Landingpage
- GitHub Releases für versionierte Installerpakete
- SHA256-Prüfsummen für Downloads
- klare Versionsanzeige
- keine Ticket- oder Schuldaten auf der Projektwebsite

### Hauptnavigation

- Was ist das?
- Was brauche ich?
- Raspberry Pi vorbereiten
- Installer
- Domain & Internetzugang
- Datenschutz
- Für Administratoren
- Updates & Backup
- Fehlerbehebung

## 15. Sicherheitsprinzipien

- keine Secrets im Git-Repository
- zufällige Tokens
- sichere Passwort-Hashes
- Datenbankzugang nur lokal
- keine öffentliche MariaDB
- Webserver nur für benötigte Dienste
- Systemverwaltung nicht standardmäßig über den öffentlichen Tunnel
- automatische Sicherheitsupdates soweit sinnvoll kontrolliert
- Backup- und Wiederherstellungsweg
- nachvollziehbare Logs ohne unnötige personenbezogene Daten
- Installer darf vorhandene Systeme nicht unbemerkt überschreiben

## 16. Referenzprojekt

`hnrgsko/ticketsystem-schul-it` wird ausschließlich lesend ausgewertet.

Zu extrahierende Bereiche sind insbesondere:

- Datenmodell und Migrationen
- öffentliche Ticketoberfläche
- Adminbereich
- Wissensdatenbank
- Ticketstatus
- Sicherheitslogik
- Konfigurationsstruktur
- AIS/gsKI-Einbindung
- Branding-Mechanismen
- Dokumentationsbausteine

Schulspezifische Werte aus der bestehenden Installation werden nicht als feste Standardwerte übernommen.

## 17. Update-Service

Der Update-Service ist Bestandteil der Grundarchitektur und kein nachträgliches Zusatzmodul.

Jede aktive Installation prüft selbstständig in einem konfigurierbaren Intervall auf veröffentlichte stabile Versionen. Dafür muss sich die Schule nicht zentral bei einem eigenen Update-Server registrieren.

Bei einer neueren Version erscheint für berechtigte Administratoren eine sichtbare Update-Meldung. Angezeigt werden mindestens:

- neue Versionsnummer
- Veröffentlichungsdatum
- kurze Release Notes
- Sicherheits-/Dringlichkeitshinweis
- benötigte Neustarts
- erwartete Datenbankmigrationen
- vorhandener freier Speicher
- Zeitpunkt des letzten erfolgreichen Backups

Der Standardablauf lautet:

1. neue Version erkennen
2. Administrator informieren
3. Administrator bestätigt „Update installieren“
4. Vorprüfung durchführen
5. frisches Backup von Datenbank und Konfiguration anlegen
6. Releasepaket herunterladen
7. Prüfsumme und digitale Signatur prüfen
8. Wartungsmodus aktivieren
9. neue Version in ein separates Release-Verzeichnis installieren
10. erforderliche Datenbankmigrationen ausführen
11. aktive Version atomar umschalten
12. Dienste neu laden
13. Health-Checks durchführen
14. Erfolg protokollieren und Wartungsmodus beenden

Schlägt das Update vor der Datenbankänderung fehl, wird ohne Umschalten abgebrochen. Schlägt es nach einer Datenbankmigration fehl, muss der Wiederherstellungsweg das zuvor angelegte Datenbankbackup berücksichtigen.

Der Webserverprozess erhält keine allgemeine Root-Shell. Ein eigener lokaler Update-Dienst führt ausschließlich klar definierte, signierte Updateaktionen aus.

Details: [UPDATE-SERVICE.md](UPDATE-SERVICE.md).

## 18. Nächste technische Phase

Als Nächstes wird die technische Zielarchitektur konkretisiert:

1. Verzeichnisstruktur auf dem Raspberry Pi
2. Apache-Konfiguration
3. PHP-Version und Module
4. MariaDB-Version und lokale Rechte
5. sichere private Konfiguration
6. Migrationsrunner für Neuinstallationen
7. Bootstrap-Installer
8. erster lokaler Setup-Endpunkt
9. Paketierung für grafische Installation
10. Landingpage-Grundgerüst

Erst danach wird die bestehende Ticketsystem-Anwendung in eine schulneutrale, portable Distribution überführt.
