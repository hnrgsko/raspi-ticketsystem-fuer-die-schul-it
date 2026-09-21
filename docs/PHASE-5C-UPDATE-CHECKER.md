# Phase 5c – Updateprüfung

## Ziel

Installierte Schul-IT-Instanzen sollen neue stabile Releases erkennen, ohne Telemetrie oder zentrale Kundendatenbank.

## Umsetzung

- fester öffentlicher Release-Endpunkt des GitHub-Repositories
- systemd-Timer alle sechs Stunden
- zufällige Verzögerung bis 20 Minuten
- manuelle Prüfung unter **Admin → System → Updates**
- Update-Hinweis im normalen Adminbereich
- installierte Version im Admin-Footer
- Release Notes werden als reiner Text angezeigt

## Datenschutz

Die Instanz fragt nur öffentliche Release-Metadaten ab.

Nicht übertragen werden:

- Schulname
- Domain
- Tickets
- Benutzerkonten
- Statistiken
- FAQ-Inhalte

## Sicherheit

Die automatische Installation ist bewusst noch deaktiviert.

Vor einer Installationsfunktion wird benötigt:

- signiertes Release-Manifest
- fester öffentlicher Signaturschlüssel
- Hash-Prüfung des Pakets
- verpflichtendes Vorab-Backup
- Health-Checks und Rollback

Bis dahin erkennt das System Updates, lädt aber keinen Anwendungscode automatisch herunter.

## Dienste

- `schulit-update-check.service`
- `schulit-update-check.timer`

Status:

`/var/lib/schulit/update-state/status.json`

## Realtest später

1. Installer ausführen.
2. Timerstatus prüfen.
3. Admin → System → Updates öffnen.
4. „Jetzt nach Updates suchen“ auslösen.
5. Verhalten ohne veröffentlichtes Release prüfen.
6. später Testrelease veröffentlichen und Banner prüfen.

## Hardwaretest Raspberry Pi 4

Am 21.09.2026 erfolgreich getestet:
- Admin → System → Updates ist erreichbar,
- manuelle Prüfung über „Jetzt nach Updates suchen“ funktioniert,
- für die aktuelle Development-Version wurde korrekt kein stabiles Release erkannt,
- es wurde erwartungsgemäß nichts installiert.


## Entwicklungsinstanzen

Für Testgeräte mit `INSTALL_CHANNEL=development` gibt es zusätzlich unter **Admin → System → Updates** den Button:

**Entwicklerversion aus GitHub aktualisieren**

Dieser Weg ist ausdrücklich nur für Entwicklungsinstanzen gedacht. Er:

- lädt ausschließlich den fest hinterlegten Branch `main` dieses Repositories,
- startet den normalen Installer als separaten systemd-Oneshot-Job,
- läuft unabhängig vom `schulit-setupd`, damit dessen Neustart während der Installation den Updatejob nicht beendet,
- führt dadurch weiterhin alle normalen Installer-Schritte, Migrationen und Systemprüfungen aus,
- speichert einen kompakten Status und ein lokales Updateprotokoll.

Der Browser übergibt dabei weder beliebige Shellbefehle noch eine frei wählbare Download-URL.

Dieser Entwicklungsweg ersetzt **nicht** den geplanten signierten Stable-Updater. Auf späteren Stable-Installationen wird er nicht angeboten.
