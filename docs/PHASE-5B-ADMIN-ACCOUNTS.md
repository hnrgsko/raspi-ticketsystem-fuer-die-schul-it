# Phase 5b – Administratorkonten

## Ziel

Die portable Schul-IT-Instanz muss mehrere Administratorkonten verwalten können, ohne direkten Datenbankzugriff.

## Rollen

### System-Admin

Kann zusätzlich zu allen Ticketfunktionen:

- Systemeinstellungen ändern
- KI-Assistent konfigurieren
- Cloudflare Tunnel verwalten
- weitere Admin-Konten anlegen
- Rollen und Aktivstatus anderer Admins ändern
- Startpasswörter anderer Admins zurücksetzen

### Ticket-Admin

Kann:

- Tickets bearbeiten
- FAQ moderieren
- Statistiken sehen

Kann keine Systemkonfiguration ändern.

## Eigenes Passwort

Jedes Administratorkonto besitzt unter:

**Admin → Passwort ändern**

eine eigene Passwortseite.

Anforderungen:

- aktuelles Passwort erforderlich
- neues Passwort mindestens 14 Zeichen
- Wiederholung muss übereinstimmen
- neues Passwort darf nicht dem alten entsprechen

## Neue Admin-Konten

System-Admins können unter:

**Admin → System → Administratorkonten**

neue Konten anlegen.

Benötigt werden:

- Anzeigename
- Benutzername
- Rolle
- Startpasswort

Ein neu angelegtes Konto erhält automatisch:

`must_change_password = 1`

Beim ersten Login wird das Konto zwingend auf **Passwort ändern** geleitet.

## Startpasswort zurücksetzen

Ein System-Admin kann für andere Konten ein neues Startpasswort festlegen.

Danach muss das betroffene Konto beim nächsten Login erneut ein eigenes Passwort setzen.

Das eigene Passwort wird nicht über diese Funktion zurückgesetzt.

## Sperren und Rollen ändern

System-Admins können andere Konten:

- aktivieren
- sperren
- zwischen `ticket_admin` und `system_admin` umstellen

Schutzregeln:

- das eigene Konto kann in dieser Oberfläche nicht selbst gesperrt oder herabgestuft werden
- mindestens ein aktiver System-Admin muss erhalten bleiben
- Konten werden nicht physisch gelöscht, sondern deaktiviert

## Sicherheit

- Passwörter werden ausschließlich mit PHP `password_hash()` gespeichert
- Startpasswort mindestens 14 Zeichen
- CSRF-Schutz auf allen Aktionen
- geänderte eigene Passwörter aktualisieren die laufende Sitzung sicher
- ein Passwort-Reset macht bestehende Sessions des Zielkontos ungültig, weil die Session an den Passwort-Hash gebunden ist

## Realtest später

1. zweiten Ticket-Admin anlegen
2. erster Login mit Startpasswort
3. erzwungenen Passwortwechsel prüfen
4. Ticketbearbeitung als Ticket-Admin testen
5. sicherstellen, dass Systembereich nicht verfügbar ist
6. zweites System-Admin-Konto anlegen
7. Sperren / Reaktivieren testen
8. Passwort-Reset testen
9. Schutz des letzten aktiven System-Admins prüfen

## Hardwaretest Raspberry Pi 4

Am 21.09.2026 erfolgreich getestet:
- zweites Administratorkonto über Admin → System angelegt,
- Rollenverwaltung funktioniert,
- Startpasswort funktioniert,
- erzwungener Passwortwechsel beim ersten Login funktioniert,
- anschließende Passwortänderung funktioniert.
