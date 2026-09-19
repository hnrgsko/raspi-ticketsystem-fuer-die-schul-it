# Phase 2 – Einrichtungsassistent

## Ziel

Phase 2 macht aus der technischen Statusseite einen geführten Ersteinrichtungsassistenten.

Der Einsteigerweg umfasst:

1. Neue Installation oder Wiederherstellung auswählen
2. Schulname und Schulnummer/Schulkennung erfassen
3. ersten System-Administrator erstellen
4. lokale MariaDB-Anwendungsdatenbank erzeugen
5. Recovery-Code erzeugen und einmalig anzeigen
6. Abschlussstatus anzeigen

Die Backup-Wiederherstellung hat bereits einen eigenen Einstieg. Die tatsächliche USB-Backup-Auswahl folgt mit der Backup-Phase, weil hierfür zuerst das portable Sicherungsformat implementiert werden muss.

## Berechtigungsmodell

Der Apache-/PHP-Prozess erhält keine Root-Shell.

Stattdessen läuft lokal:

`schulit-setupd.service`

Kommunikation:

```text
Browser
  ↓
PHP Setup-Assistent
  ↓
Unix-Socket /run/schulit/setupd.sock
  ↓
schulit-setupd (root)
  ↓
nur definierte Setup-Aktionen
```

Der Dienst öffnet keinen TCP-Port und akzeptiert keine beliebigen Shellbefehle oder Download-URLs.

## Datenbank

Phase 2 legt die lokale Datenbank `schulit` und den Anwendungsbenutzer `schulit_app@localhost` an.

Der Anwendungsbenutzer erhält nur:

- SELECT
- INSERT
- UPDATE
- DELETE

Schemaänderungen bleiben privilegierten Installations-/Updateprozessen vorbehalten.

Die erste portable Migration enthält nur:

- `schema_migrations`
- `system_settings`
- `admin_users`

Das fachliche Ticket-Schema wird später getrennt aus der Read-only-Referenz schulneutral portiert.

## Rollen

### system_admin

Kann später alle Ticket-Admin-Funktionen und zusätzlich Systemverwaltung, Updates, Backups, Tunnel und Recovery nutzen.

### ticket_admin

Kann die fachliche Ticketverwaltung nutzen, jedoch keine privilegierten Systemaktionen ausführen.

Der erste Account einer neuen Installation ist immer `system_admin`.

## Recovery-Code

Der Recovery-Code enthält sichtbar die Schulnummer/Schulkennung und einen kryptografisch zufälligen geheimen Anteil.

Beispiel:

```text
123456-ABCD-EFGH-JKLM-NPQR-STUV
```

Die Schulnummer ist keine Sicherheitskomponente. Die Sicherheit entsteht ausschließlich aus dem zufälligen Anteil.

Auf dem Pi wird nicht der Recovery-Code selbst gespeichert. Es werden nur Salt und scrypt-Prüfwert root-only abgelegt.

Der Klartextcode wird nur unmittelbar nach der Ersteinrichtung an den Browser zurückgegeben und soll außerhalb des Pi sicher gespeichert oder ausgedruckt werden.

## Aktueller Stand der Restore-Option

Der Button „Aus Backup wiederherstellen“ ist bereits Bestandteil des Assistenten, führt aber noch nicht zu einer Wiederherstellung.

Das ist beabsichtigt: Erst wird in der nächsten Phase ein versioniertes, verschlüsselbares Backupformat mit USB-Erkennung definiert. Danach wird dieser Einstieg mit der echten Wiederherstellungslogik verbunden.
