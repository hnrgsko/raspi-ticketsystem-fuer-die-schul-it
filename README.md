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

## Status

Aktuell: Produktarchitektur und Installationsweg werden festgelegt. Noch keine produktive Installation aus diesem Repository durchführen.

Siehe auch:

- [Produkt- und Installationskonzept](docs/PRODUKTKONZEPT.md)
- [Update-Service](docs/UPDATE-SERVICE.md)
- [Technische Zielarchitektur v1](docs/TECHNISCHE-ZIELARCHITEKTUR.md)
