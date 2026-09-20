# Phase 4b – Automatisiertes FAQ mit Moderation

## Ziel

Aus wiederkehrenden, bereits gelösten IT-Problemen soll mit möglichst wenig Zusatzarbeit ein gepflegtes FAQ entstehen.

Das System veröffentlicht niemals automatisch Inhalte aus Tickets. Stattdessen gilt:

**automatisch formulieren → kurz moderieren → veröffentlichen**

## Quellen für FAQ-Entwürfe

### 1. Erledigtes Ticket

Standardmäßig wird beim erstmaligen Wechsel eines Tickets auf **Erledigt** automatisch ein FAQ-Entwurf erzeugt.

- Frage: wird lokal und regelbasiert aus Defektbezeichnung bzw. Problembeschreibung formuliert.
- Antwortentwurf: kann aus der letzten internen Ticketnotiz übernommen werden.
- Kategorie: wird aus dem Ticket übernommen.
- Veröffentlichung: niemals automatisch.

System-Admins können diese Automatik im FAQ-Bereich deaktivieren.

Im Ticketdetail bleibt zusätzlich **FAQ-Entwurf erzeugen** als Nachholfunktion erhalten. Bereits vorhandene Vorschläge werden nicht dupliziert.

### 2. Direkter Admin-Vorschlag

Admins können unter **FAQ → Neue FAQ-Frage direkt formulieren** selbst angeben:

- Problemfrage
- optional einen Lösungsentwurf
- optional eine Kategorie

Der Vorschlag landet ebenfalls zuerst in der Moderation.

### 3. Normale Ticketmeldung durch das Kollegium

Für Kolleginnen und Kollegen gibt es **keinen gesonderten FAQ-Vorschlagsweg**.

Sie nutzen ausschließlich das normale Ticketsystem und formulieren dort ihr **Problem / ihre Frage**. Erst wenn das Ticket gelöst und auf **Erledigt** gesetzt wird, entsteht daraus im Hintergrund automatisch ein FAQ-Entwurf.

Damit wächst das FAQ aus realen Supportfällen, ohne dass das Kollegium zusätzliche Arbeit hat.


## Moderation

Unter **Admin → FAQ** erscheinen alle offenen Vorschläge.

Der Admin kann dort:

- die öffentliche Frage bearbeiten
- die öffentliche Antwort bearbeiten oder ergänzen
- die Kategorie ändern
- **Prüfen & veröffentlichen**
- **Verwerfen**

Bei Entwürfen aus Tickets wird ausdrücklich darauf hingewiesen, dass ein Antwortentwurf aus einer internen Notiz stammen kann und vor Veröffentlichung auf interne oder personenbezogene Angaben geprüft werden muss.

## Veröffentlichte FAQ

Veröffentlichte FAQ erscheinen auf der Kollegiumsseite als aufklappbare Fragen.

Admins können veröffentlichte Einträge:

- ausblenden
- wieder veröffentlichen

## Datenschutz und Sicherheit

- keine automatische Veröffentlichung aus internen Tickets
- keine zusätzliche FAQ-Erfassung durch das Kollegium; Quelle sind normale Support-Tickets
- alle Ausgaben HTML-escaped
- CSRF-Schutz
- Session-basierte Rate-Limits für Kollegiums-Vorschläge
- interne Ticketnotizen sind höchstens Entwurf und müssen moderiert werden
- FAQ-Daten werden mit der bestehenden Datenbanksicherung gesichert

## Datenmodell

Migration:

`database/migrations/003_faq_moderation.sql`

Tabellen:

- `faq_proposals` – Moderationswarteschlange
- `faq_entries` – veröffentlichte bzw. deaktivierte FAQ

Quellen:

- `ticket`
- `admin`
- `colleague` bleibt im Datenmodell vorerst reserviert, wird im aktuellen Workflow aber nicht verwendet

## Erster Realtest

1. Installer erneut ausführen und Migration 003 anwenden.
2. Admin → FAQ öffnen.
3. Ein Ticket mit einer sinnvollen letzten internen Lösungsnotiz auf **Erledigt** setzen.
4. Prüfen, dass automatisch ein FAQ-Entwurf erscheint.
5. Frage und Antwort kontrollieren und veröffentlichen.
6. Kollegiumsseite öffnen und FAQ prüfen.
7. Ein weiteres normales Support-Ticket mit einer als Frage formulierten Problembeschreibung erstellen und lösen.
8. Prüfen, dass daraus ebenfalls automatisch ein FAQ-Entwurf entsteht.
9. Automatik als System-Admin testweise aus- und wieder einschalten.
