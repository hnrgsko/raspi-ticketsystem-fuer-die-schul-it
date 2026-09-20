# Phase 4b – Automatisiertes FAQ mit Moderation

## Ziel

Aus wiederkehrenden, bereits gelösten IT-Problemen soll mit möglichst wenig Zusatzarbeit ein gepflegtes FAQ entstehen.

Das System veröffentlicht niemals automatisch Inhalte aus Tickets. Stattdessen gilt:

**automatisch formulieren → kurz moderieren → veröffentlichen**

## Quellen für FAQ-Vorschläge

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

### 3. Kollegiums-Vorschlag

Auf der Kollegiumsseite gibt es unter den veröffentlichten FAQ:

**Fehlt eine Problemfrage? Für das FAQ vorschlagen**

Kolleginnen und Kollegen können:

- eine Problemfrage formulieren
- optional eine Kategorie wählen

Es wird absichtlich kein Name oder Kürzel abgefragt. Die Frage wird nicht direkt veröffentlicht.

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
- Kollegiums-Vorschläge ohne Namensfeld
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
- `colleague`

## Erster Realtest

1. Installer erneut ausführen und Migration 003 anwenden.
2. Admin → FAQ öffnen.
3. Ein Ticket mit einer sinnvollen letzten internen Lösungsnotiz auf **Erledigt** setzen.
4. Prüfen, dass automatisch ein FAQ-Entwurf erscheint.
5. Frage und Antwort kontrollieren und veröffentlichen.
6. Kollegiumsseite öffnen und FAQ prüfen.
7. Dort eine neue Problemfrage vorschlagen.
8. Admin → FAQ öffnen und den Kollegiums-Vorschlag moderieren.
9. Automatik als System-Admin testweise aus- und wieder einschalten.
