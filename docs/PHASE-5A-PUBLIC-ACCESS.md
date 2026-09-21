# Phase 5a – Öffentlicher Zugang mit Cloudflare Tunnel

## Ziel

Das Schul-IT Ticketsystem soll über eine schulindividuelle Domain oder Subdomain erreichbar sein, ohne dass am Schul- oder Heimrouter eingehende Ports freigegeben werden müssen.

Beispiel:

`support.schule.de`

## Architektur

Der Raspberry Pi stellt die Anwendung weiterhin lokal auf:

`http://127.0.0.1:8081`

bereit.

Ein Cloudflare Tunnel baut ausschließlich eine ausgehende Verbindung zu Cloudflare auf. Im Cloudflare-Dashboard wird die veröffentlichte Anwendung auf den lokalen Dienst `http://localhost:8081` geroutet.

## Voraussetzungen

- Cloudflare-Konto
- Domain in Cloudflare
- remotely-managed Cloudflare Tunnel
- öffentlicher Hostname / Subdomain
- Tunnel-Token

## Admin-Workflow

Unter **Admin → System → Domain / Cloudflare Tunnel**:

1. gewünschten öffentlichen Hostnamen eintragen
2. Tunnel-Token einfügen
   - alternativ kann der komplette von Cloudflare angezeigte `cloudflared service install …`-Befehl eingefügt werden
3. **Tunnel installieren & aktivieren**
4. optional **Verbindung testen**

Das System:

- erkennt Raspberry Pi ARM / ARM64 sowie amd64-Entwicklungsumgebungen
- lädt `cloudflared` ausschließlich über die offizielle Cloudflare-GitHub-Release-URL
- installiert die Binärdatei nach `/usr/local/bin/cloudflared`
- speichert den Tunnel-Token root-only
- erzeugt einen eigenen systemd-Dienst `schulit-tunnel.service`
- startet den Tunnel automatisch beim Boot
- zeigt den öffentlichen Kollegiumslink im Adminbereich

## Cloudflare-Konfiguration

Im Cloudflare-Dashboard wird für den Tunnel eine veröffentlichte Anwendung angelegt:

- Hostname: z. B. `support.schule.de`
- Service/Origin: `http://localhost:8081`

## Kollegiumslink

Die Anwendung bleibt zusätzlich durch ihr bestehendes Zugangstoken geschützt.

Der Adminbereich zeigt nach erfolgreicher Konfiguration einen Link nach dem Schema:

`https://support.schule.de/?access=<SCHUL-TOKEN>`

Dieser Link sollte ausschließlich im geschützten Schulportal oder über interne Kommunikationswege verteilt werden.


## Kollegiumslink erneuern

Wenn der öffentliche Kollegiumslink versehentlich außerhalb des vorgesehenen Kreises sichtbar wurde, kann ein System-Admin unter **Admin → System → Domain / Cloudflare Tunnel** den Zugang mit **Kollegiumslink erneuern** rotieren.

Dabei:

- wird ein neues kryptografisch zufälliges Zugangstoken erzeugt,
- wird der bisherige Kollegiumslink sofort ungültig,
- werden bestehende öffentliche Kollegiumssitzungen beendet,
- bleiben Cloudflare Tunnel, Domain, Tickets, FAQ und Admin-Sitzungen unverändert.

Anschließend muss nur der neu angezeigte Kollegiumslink im geschützten Schulportal bzw. über den vorgesehenen internen Weg verteilt werden.

## Sicherheit

### Kein Portforwarding

Der Router benötigt keine eingehende Portweiterleitung.

### Tunnel-Token

Der Cloudflare Tunnel-Token:

- wird mit Modus 0600 gespeichert
- gehört root
- wird nach dem Speichern nicht erneut in der Oberfläche angezeigt
- wird nicht in MariaDB abgelegt
- wird nicht in Webserver-Logs geschrieben

### systemd-Dienst

Der Connector läuft als:

- Benutzer `nobody`
- Gruppe `nogroup`
- `NoNewPrivileges=true`
- `PrivateTmp=true`
- `ProtectHome=true`
- `ProtectSystem=strict`

### Entfernung

Ein System-Admin kann den Tunnel trennen.

Dabei werden auf dem Raspberry Pi:

- Dienst deaktiviert
- Dienstdatei entfernt
- gespeicherter Tunnel-Token gelöscht
- lokale Tunnelkonfiguration gelöscht

Tickets, FAQ, Datenbank und lokaler Zugriff bleiben erhalten.

Die Konfiguration im Cloudflare-Konto selbst wird dadurch nicht gelöscht.

## Restore-Verhalten

Ein Restore soll einen externen Tunnel nicht ungefragt automatisch auf einem Ersatzgerät aktivieren.

Nach einem vollständigen Gerätewechsel wird der öffentliche Zugang bewusst neu verbunden. Dadurch wird vermieden, dass alter und neuer Raspberry Pi versehentlich parallel denselben Zugang bereitstellen.

## Systemprüfung

Wenn ein Tunnel lokal eingerichtet ist, prüft der Installer zusätzlich:

- Tunnel-Konfiguration root-only
- Tunnel-Token root-only
- Rechte von `cloudflared`
- Rechte der systemd-Unit
- aktiver Tunnel-Dienst

Die Erreichbarkeit der öffentlichen Domain wird absichtlich separat über **Verbindung testen** geprüft, damit vorübergehende DNS- oder Internetprobleme kein lokales Softwareupdate blockieren.

## Realtest auf Raspberry Pi 4

Erfolgreich auf echter Hardware bestätigt:

- Cloudflare Tunnel im Dashboard angelegt
- Published Application auf `http://localhost:8081` geroutet
- `cloudflared` über den Adminbereich installiert
- Tunnel-Dienst aktiv und öffentlicher Hostname erreichbar
- öffentlicher Kollegiumslink mit Zugangstoken funktioniert über HTTPS
- Zugangstoken wird nach erfolgreichem Einstieg aus der URL entfernt
- öffentlicher Session-Cookie funktioniert auch beim Einstieg von einer anderen Site
- Kollegiumslink kann im Adminbereich rotiert werden
- alter Kollegiumslink wird nach Rotation ungültig
- bestehende Kollegiumssitzungen werden bei Rotation beendet

Während des Realtests wurden zusätzlich zwei Raspberry-Pi-spezifische Fehler behoben:

- `/run/schulit` erhält nach einem Neustart wieder korrekte Gruppenrechte für den Webprozess
- die temporäre `cloudflared`-Datei wird nicht mehr unter einem möglichen `noexec`-`/run` ausgeführt

Zusätzlich erfolgreich bestätigt:

- Raspberry Pi mit bereits eingerichtetem Tunnel neu gestartet
- `schulit-tunnel.service` startet danach automatisch wieder
- öffentlicher Kollegiumslink funktioniert nach dem Neustart weiterhin

Optionaler Resttest:

- Tunnel testweise trennen und bestätigen, dass der lokale Zugriff erhalten bleibt. Dieser Test verändert bewusst die Tunnel-Konfiguration und ist für die grundlegende Funktionsfreigabe nicht mehr erforderlich.
