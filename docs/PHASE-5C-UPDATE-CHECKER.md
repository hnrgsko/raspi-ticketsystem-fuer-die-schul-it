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
