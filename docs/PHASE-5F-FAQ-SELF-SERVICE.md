# Phase 5f – FAQ-Self-Service vor dem Ticket

## Ziel

Veröffentlichte FAQ sollen nicht nur passiv auf der Startseite stehen, sondern während einer normalen Supportmeldung automatisch helfen, bevor ein Ticket abgeschickt wird.

## Ablauf

1. Kollegin oder Kollege öffnet **Hilfe / Problem**.
2. Kategorie, Gerät/System und Problem/Frage werden eingegeben.
3. Die Instanz sucht lokal in veröffentlichten FAQ.
4. Bis zu drei passende Lösungen werden direkt im Formular vorgeschlagen.
5. Die Lösung kann aufgeklappt werden.
6. Optional kann **„Das hat geholfen – kein Ticket nötig“** gewählt werden.
7. Wenn die Lösung nicht reicht, bleibt das normale Ticketformular unverändert nutzbar.

## Datenschutz

Die Suche findet ausschließlich auf dem Raspberry Pi statt.

Die eingegebene Problembeschreibung wird nicht an:

- Cloudflare
- AIS.chat
- OpenAI
- GitHub
- andere externe Dienste

zur FAQ-Suche übertragen.

## Ranking

Berücksichtigt werden:

- Wörter aus Gerät/System und Problembeschreibung
- ausgewählte Ticketkategorie
- Frage und Antwort der veröffentlichten FAQ
- Häufigkeit realer Tickets, die bereits mit einer FAQ verknüpft sind

FAQ mit wiederholt real auftretenden Problemen erhalten dadurch einen kleinen zusätzlichen Gewichtungsbonus.

## Statistik

Anonym aggregiert werden:

- FAQ auf der Startseite geöffnet
- vorgeschlagene FAQ während der Ticketerfassung geöffnet
- „Das hat geholfen – kein Ticket nötig“
- Ticket nach vorheriger FAQ-Nutzung

Dadurch kann später beurteilt werden, ob FAQ tatsächlich Supportfälle abfangen.

Es werden keine individuellen Nutzerprofile erzeugt.

## Technische Komponenten

- `application/faq-suggest.php`
- `application/assets/faq-suggest.js`
- `app_faq_suggestions()`

## Realtest später

1. mindestens zwei FAQ veröffentlichen
2. neues Supportticket öffnen
3. passende Kategorie wählen
4. relevante Stichwörter in Gerät/Problem eingeben
5. prüfen, ob FAQ-Vorschläge erscheinen
6. Vorschlag öffnen
7. „Das hat geholfen“ testen
8. Statistik prüfen
9. alternativ Ticket trotzdem abschicken
10. „Ticket nach FAQ-Nutzung“ in Statistik prüfen


## Hardwaretest Raspberry Pi 4

Am 21.09.2026 auf echter Hardware bestätigt:

- öffentliche FAQ wurde geöffnet und als Nutzung gezählt
- ein automatischer FAQ-Vorschlag wurde geöffnet und als Nutzung gezählt
- **„Hat geholfen“** wurde ausgelöst und korrekt gezählt
- die Kennzahl **„Ticket nach FAQ“** wurde im Admin-Statistikbereich sichtbar

Beim Test fiel auf, dass die ursprüngliche Sitzungslogik eine frühere FAQ-Nutzung auf mehrere spätere Tickets derselben Browsersitzung übertragen konnte. Das wurde korrigiert: FAQ-/Assistenten-Zuordnung wird nun nach dem nächsten Ticket verbraucht; **„Hat geholfen“** beendet die FAQ-Zuordnung bereits vorher. Dadurch werden spätere, unabhängige Tickets nicht mehr fälschlich dem Self-Service zugerechnet.
