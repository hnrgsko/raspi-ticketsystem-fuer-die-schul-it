# Phase 4d – FAQ-Autopilot

## Ziel

Das FAQ soll möglichst automatisch aus realen Supportfällen wachsen. Die menschliche Arbeit soll sich auf eine kurze inhaltliche Kontrolle beschränken.

## Ablauf

1. Kollegium stellt ein normales Ticket.
2. Admin löst das Ticket und setzt es auf **Erledigt**.
3. Das System erzeugt automatisch einen FAQ-Entwurf.
4. Der FAQ-Autopilot bewertet den Entwurf.
5. Der Admin bestätigt mit möglichst wenig Aufwand:
   - neue FAQ veröffentlichen,
   - bestehende FAQ aktualisieren,
   - oder den Fall als ungeeignet verwerfen.

Es wird weiterhin niemals automatisch ohne Moderation veröffentlicht.

## Automatische Bewertung

Der Autopilot arbeitet lokal auf dem Raspberry Pi und benötigt keinen externen KI-Dienst.

Er nutzt:

- Kategorie des Tickets,
- Problem-/Fragetext,
- vorhandene veröffentlichte FAQ,
- Schlüsselwortüberschneidungen,
- vorhandene Lösungsnotiz,
- Hinweise auf ein konkretes inventarisiertes Gerät.

### Neue FAQ sinnvoll

Wenn keine ausreichend ähnliche veröffentlichte FAQ gefunden wird und eine Lösungsnotiz vorhanden ist.

### Bestehende FAQ wahrscheinlich

Wenn die Wort- und Themenüberschneidung mit einer vorhandenen FAQ hoch ist.

Der Admin sieht:

- die bestehende FAQ,
- den Ähnlichkeitswert,
- wie viele Tickets bereits mit ihr verknüpft sind,
- den neuen Entwurf.

Dann kann er **Bestehende FAQ aktualisieren** wählen.

### Bitte kurz prüfen

Zum Beispiel wenn:

- noch keine Lösungsnotiz vorhanden ist,
- der Fall ein konkretes inventarisiertes Gerät betrifft,
- eine teilweise ähnliche FAQ existiert, die Ähnlichkeit aber nicht eindeutig genug ist.

## Dublettenvermeidung

Ähnliche Tickets sollen nicht zu immer neuen FAQ führen.

Dafür speichert das System bei einem passenden bestehenden Eintrag:

- `suggested_entry_id`
- `similarity_score`
- `recommendation`
- `recommendation_reason`

## Häufigkeit realer Probleme

Neue Tabelle:

`faq_entry_tickets`

Sie verknüpft veröffentlichte FAQ mit den zugrunde liegenden Tickets.

Dadurch kann der Admin sehen, wie viele reale Supportfälle bereits hinter einer FAQ stehen.

Diese Information kann später für Sortierung, Priorisierung und Statistiken genutzt werden.

## Moderation

Im Bereich **Admin → FAQ** werden Vorschläge farblich unterschieden:

- Grün: **Neue FAQ sinnvoll**
- Blau: **Bestehende FAQ wahrscheinlich**
- Gelb: **Bitte kurz prüfen**

Mögliche Aktionen:

- **Bestehende FAQ aktualisieren**
- **Trotzdem neue FAQ veröffentlichen**
- **Prüfen & veröffentlichen**
- **Kein FAQ-Fall**

## Datenschutz

Der Autopilot verarbeitet ausschließlich Daten innerhalb der lokalen Schul-IT-Instanz.

Es werden keine Ticketinhalte an externe KI-Dienste übertragen.

Interne Notizen bleiben weiterhin nur ein Entwurf und müssen vor Veröffentlichung auf personenbezogene oder interne Informationen geprüft werden.

## Datenbank

Migration:

`database/migrations/005_faq_autopilot.sql`

Neue bzw. erweiterte Daten:

- Empfehlung pro FAQ-Entwurf
- Begründung
- vorgeschlagene bestehende FAQ
- Ähnlichkeitswert
- Ticket-zu-FAQ-Verknüpfungen

## Realtest

1. Update installieren und Migration 005 anwenden.
2. Ein Ticket mit einer neuen Problemart lösen.
3. Prüfen, ob **Neue FAQ sinnvoll** vorgeschlagen wird.
4. FAQ veröffentlichen.
5. Ein zweites Ticket mit sehr ähnlichem Problem lösen.
6. Prüfen, ob **Bestehende FAQ wahrscheinlich** erscheint.
7. **Bestehende FAQ aktualisieren** testen.
8. Prüfen, ob der Ticketzähler der FAQ steigt.
9. Einen individuellen Hardwaredefekt mit Inventarnummer lösen.
10. Prüfen, ob **Bitte kurz prüfen** vorgeschlagen wird.


## Hardwaretest Raspberry Pi 4 – Teiltest 1

Am 20.09.2026 auf echter Hardware bestätigt:

- Ticket mit interner Lösungsnotiz auf **Erledigt** gesetzt
- FAQ-Entwurf wurde automatisch erzeugt
- Autopilot bewertete den Fall als **Neue FAQ sinnvoll**

Zusätzlich auf echter Hardware bestätigt:

- FAQ-Entwurf erfolgreich veröffentlicht
- veröffentlichte FAQ erscheint auf der Kollegiumsseite
- Frage ist aufklappbar und Antwort wird korrekt dargestellt

Noch offen in diesem Testblock:

- zweites ähnliches Ticket
- Erkennung **Bestehende FAQ wahrscheinlich**
- Zusammenführen / Aktualisieren einer bestehenden FAQ
- Erhöhung des verknüpften Ticketzählers
