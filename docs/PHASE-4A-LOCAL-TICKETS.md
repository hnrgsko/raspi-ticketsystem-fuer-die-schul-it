# Phase 4a – Lokales Ticketsystem

## Ziel

Nach Abschluss der technischen Einrichtung läuft das eigentliche schulneutrale Ticketsystem lokal auf dem Raspberry Pi.

Die technische Setup-/Systemoberfläche bleibt auf Port 8080.

Die Anwendung läuft getrennt auf Port 8081:

- Kollegiumsseite: `http://<pi>:8081/`
- Ticket-Admin: `http://<pi>:8081/admin/`

Später zeigt der öffentliche Cloudflare-Tunnel auf `127.0.0.1:8081`.

## Kollegiumszugang

Der öffentliche Einstieg verwendet einen zufälligen Zugangs-Token.

Beispiel:

```text
http://192.168.1.50:8081/?access=<zufälliger-token>
```

Nach erfolgreicher Prüfung:

1. wird eine Sitzung erzeugt,
2. der Token wird aus der Browseradresse entfernt,
3. weitere Navigation erfolgt ohne Token in der URL.

Apache protokolliert für diese Site absichtlich keine Querystrings.

Der Token liegt lokal unter:

```text
/etc/schulit/access-token
```

und wird ab Phase 4a in verschlüsselten Systembackups mitgesichert, damit der Schulportal-Link nach einer Wiederherstellung gleich bleiben kann.

## Ticketablauf in Phase 4a

### Support

1. „Hilfe / Problem“ auswählen
2. Kategorie auswählen
3. Name
4. Kürzel
5. Raum / Ort
6. Gerät / System
7. optionale Inventar-/Seriennummer
8. Problembeschreibung
9. optionaler Zeitpunkt / Auftreten
10. Ticket absenden

### Defekt

Defekte laufen über einen eigenen Einstieg.

Sie erhalten automatisch:

- Kategorie `Defekt / Hardware`
- Priorität `hoch`

Die Priorität kann im Adminbereich nicht heruntergesetzt werden.

## Startkategorien

- Schulportal
- Dienstmail
- WLAN / Internet
- iPad / Tablet
- Digitale Tafel / Smartboard
- Computer / Notebook
- Kabel / Zubehör
- Software / Apps
- Benutzerkonto / Passwort
- Defekt / Hardware
- Sonstiges

Die Kategorien sind Startwerte und werden später administrierbar.

## Ticketstatus

Nach dem Absenden wird eine Ticketnummer angezeigt.

Statusabfrage benötigt:

- Schulkennung
- Ticketnummer

Angezeigt werden ausschließlich:

- Status
- Erstellungszeitpunkt
- Zeitpunkt der letzten Statusänderung

Ticketinhalte werden über die Statusabfrage nicht ausgegeben.

## Adminbereich

Der erste im Setup angelegte Account kann sich direkt anmelden.

Unterstützte Rollen:

### system_admin

Darf alle Ticketfunktionen verwenden. Später zusätzlich:

- Systemverwaltung
- Updates
- Backup
- Tunnel/Domain
- Recovery
- Benutzerverwaltung

### ticket_admin

Darf die fachliche Ticketverwaltung verwenden, aber keine privilegierten Systemfunktionen.

Phase 4a enthält:

- Ticketliste
- Filter nach Status/Priorität
- Detailansicht
- Status ändern
- Priorität ändern
- interne Notizen
- erledigte Tickets archivieren
- archivierte Tickets zurückholen

## Datenbankmigration

`002_ticket_core.sql` ergänzt das portable Basisschema um:

- `categories`
- `tickets`
- `ticket_comments`

Ausstehende Migrationen werden bei Installer-Updates kontrolliert über `schema_migrations` angewendet.

Bei einer frischen Installation werden alle vorhandenen Migrationen während der Ersteinrichtung ausgeführt.

## Dateisystem

Anwendung:

```text
/opt/schulit/application
```

Schreibbar durch Apache sind ausschließlich die vorgesehenen Laufzeitpfade:

```text
/var/lib/schulit/sessions
/var/lib/schulit/admin-sessions
/var/lib/schulit/uploads
```

Anwendungscode selbst ist nicht durch den Webserver schreibbar.

## Noch nicht Bestandteil von Phase 4a

Folgt in den nächsten Teilschritten:

- Hilfekarten / Wissensdatenbank
- Schlagwortsuche
- maximal fünf passende Hilfevorschläge vor Ticketabgabe
- Ticket aus Wissensfluss heraus
- Wissenseintrag aus Ticket als Entwurf
- Dateiuploads
- schulindividuelle Kategorien/Branding
- gsKI/AIS-Integration
- System-Admin-Oberfläche
- Domain und Cloudflare Tunnel
