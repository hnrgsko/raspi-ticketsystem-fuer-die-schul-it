# Phase 4c – Nutzungsstatistik

## Ziel

Admins sollen sehen können, wie intensiv das Ticketsystem und der optionale KI-Assistent genutzt werden, ohne personenbezogene Nutzungsprofile anzulegen.

## Erfasste Kennzahlen

Aggregiert pro Kalendertag:

- `assistant_inline_use`
  - zählt, wenn der direkt eingebettete iframe tatsächlich fokussiert/angeklickt wird
- `assistant_bubble_open`
  - zählt das Öffnen der schwebenden Sprechblase
- `assistant_external_open`
  - zählt das Öffnen des Assistenten über einen externen Link
- `ticket_created`
  - zählt neu angelegte Tickets
- `ticket_after_assistant`
  - zählt Tickets, die in derselben Browsersitzung nach einer Assistenten-Nutzung erstellt wurden

## Datenschutz

Gespeichert werden ausschließlich Tageszähler.

Nicht gespeichert werden:

- IP-Adressen
- Namen oder Kürzel
- Browser-/Gerätekennungen
- Chat-Inhalte
- einzelne Nutzungsereignisse
- externe Chat-Nachrichten

Für Assistenten-Nutzung wird zusätzlich ein anonymer Sitzungszähler geführt. Eine Browsersitzung wird je Zugangsweg und Kalendertag höchstens einmal als Sitzung gezählt.

Wichtig: Dieselbe Sitzung kann mehrere Zugangswege verwenden. Die Sitzungszahlen der einzelnen Kanäle dürfen deshalb nicht als eindeutige Personen addiert werden.

## Technische Grenze bei fremden Chats

Bei cross-origin iframes und extern geöffneten Diensten kann das Ticketsystem nicht sehen, was innerhalb des fremden Chats passiert.

Daher gilt:

- Inline: gezählt wird der tatsächliche Fokus/Einstieg in den iframe.
- Sprechblase: gezählt wird das Öffnen.
- Extern: gezählt wird der Klick zum externen Dienst.

Es wird nicht behauptet, dass damit Nachrichtenanzahl oder Gesprächsdauer gemessen werden.

## Adminbereich

Unter **Admin → Statistik** werden angezeigt:

### Tickets

- heute
- letzte 7 Tage
- letzte 30 Tage
- gesamt
- aktuell offen
- erledigt

### KI-Assistent

Je Zugangsweg:

- heute
- 7 Tage
- 30 Tage
- gesamt
- Nutzungen
- Sitzungen

Zusätzlich:

- **Tickets nach Assistent-Nutzung**
- 30-Tage-Tagesübersicht für Tickets, Inline-Chat, Sprechblase, externen Aufruf und Tickets nach KI-Nutzung

## Datenmodell

Migration:

`database/migrations/004_usage_statistics.sql`

Tabelle:

`usage_daily`

Primärschlüssel:

`stat_date + metric`

Es werden keine Rohereignisse gespeichert.

## Interpretation

Die Kennzahl **Tickets nach Assistent-Nutzung** ist keine Aussage darüber, ob der Assistent erfolgreich oder erfolglos war. Sie zeigt nur, dass in derselben Browsersitzung nach einer Assistenten-Nutzung ein Ticket erstellt wurde.

Später kann dieselbe Architektur auch um FAQ-Nutzung erweitert werden, z. B.:

- FAQ geöffnet
- FAQ vor Ticket angesehen
- Ticket nach FAQ-Aufruf
