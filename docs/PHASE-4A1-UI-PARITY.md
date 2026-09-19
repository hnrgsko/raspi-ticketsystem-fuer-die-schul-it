# Phase 4a.1 – UI-Parität und optionaler KI-Assistent

## Ziel

Die portable Raspberry-Pi-Version soll sich bei der täglichen Ticketbearbeitung möglichst ähnlich zur bewährten GSK-Version bedienen, ohne GSK-spezifische Konfiguration fest einzubauen.

## Adminbereich

Umgesetzt:

- sichtbare Bereiche **Aktive Tickets** und **Archiv**
- Suche nach Ticketnummer, Name, Kürzel, Raum, Gerät, Beschreibung und Kategorie
- Filter nach Status, Priorität, Ticketart und Kategorie
- Sortierung nach Priorität, neueste, älteste oder zuletzt geändert
- Desktop-Tabelle
- responsive Kartenansicht auf schmalen Displays
- deutlichere Status- und Prioritätsanzeigen
- optische Defekt-Markierung
- Schnellaktionen:
  - In Bearbeitung
  - Rückfrage
  - Erledigt
  - Archivieren
- Filterzustand bleibt nach Schnellaktionen erhalten

Die portablen Rollen bleiben:

- `system_admin`
- `ticket_admin`

## Optionaler KI-Assistent

Der KI-Assistent ist standardmäßig deaktiviert.

Ein `system_admin` kann unter **System → KI-Assistent** konfigurieren:

- Aktivierung
- frei wählbare Bezeichnung, z. B. `gsKI`
- schulindividuelle URL
- optionale schwebende Sprechblase

Ist der Assistent aktiviert, wird er auf der Kollegiumsseite **vor der Ticketabgabe** als empfohlener erster Hilfeschritt angezeigt. Die Ticketabgabe bleibt weiterhin erreichbar.

## Experimentelle Sprechblase

Die Sprechblase ist eine getrennt aktivierbare experimentelle Option.

Sie:

- erscheint unten rechts auf der Kollegiumsseite
- öffnet den Assistenten eingebettet
- wird auch vom vorgeschalteten Startbutton geöffnet
- behält die Unterhaltung beim Zu- und Wiederaufklappen
- verwendet einen sandboxed iframe
- ist derzeit gezielt für AIS.chat-Dialogpartner vorgesehen

Für die eingebettete Variante akzeptiert die Anwendung nur AIS.chat-Dialogpartner-URLs nach dem unterstützten Schema auf `https://app.ais-chat.schule/`.

Andere Assistenten können weiterhin als normaler externer Link verwendet werden.

## Sicherheit

- Assistent standardmäßig aus
- Sprechblase standardmäßig aus
- externe Frames ausschließlich von `app.ais-chat.schule`
- serverseitige URL-Prüfung
- zusätzliche URL-Prüfung im Browser
- iframe sandbox
- keine Passwörter oder unnötigen personenbezogenen Daten im KI-Chat

## Noch zu testen

Auf echter Hardware:

1. Schnellaktionen aus der Ticketliste
2. Suche/Filter/Sortierung
3. KI-Assistent aktivieren
4. Assistent als vorgeschalteten Schritt prüfen
5. experimentelle Sprechblase mit AIS.chat-Dialogpartner testen
