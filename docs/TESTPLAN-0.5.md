# Gesamttest Version 0.5 – Raspberry Pi

Dieser Testplan ist für den ersten zusammenhängenden Hardwaretest nach den Phasen 4b bis 5f gedacht.

## Ziel

Nicht einzelne Zwischenstände testen, sondern einen vollständigen Ablauf:

1. bestehende Installation aktualisieren
2. Systemprüfung bestehen
3. Kollegiumsseite testen
4. Ticket- und Statusworkflow testen
5. Adminrollen und Passwortwechsel testen
6. FAQ-Autopilot testen
7. FAQ-Self-Service testen
8. Statistik testen
9. Backup testen
10. Updateprüfung testen
11. optional öffentlichen Zugang / Cloudflare testen
12. Mobile- und Browserprüfung

---

## 0. Vor dem Update

- Raspberry Pi am Strom
- Netzwerkverbindung vorhanden
- wenn möglich USB-Backupmedium anschließen
- aktuellen Recovery-Code bereithalten
- während des Updates keine Tickets bearbeiten

Optional vor dem Update ein vorhandenes Backup manuell auslösen, falls der aktuelle Stand dies bereits unterstützt.

---

## 1. Update auf aktuellen Stand

Installer neu herunterladen:

```bash
curl -fsSL https://raw.githubusercontent.com/hnrgsko/raspi-ticketsystem-fuer-die-schul-it/main/install.sh -o /tmp/install-schulit.sh
```

Dann:

```bash
sudo bash /tmp/install-schulit.sh
```

### Erwartung

Der Installer muss ohne Fehler durchlaufen.

Besonders beachten:

- Migration 003 FAQ
- Migration 004 Statistik
- Migration 005 FAQ-Autopilot
- Migration 006 sichere Ticketstatuscodes
- Update-Prüftimer wird eingerichtet
- alle abschließenden Systemprüfungen sind grün

### Bei Fehler

Nicht mehrfach blind weiterinstallieren.

Stattdessen:

```bash
sudo schulit-diagnostics
```

Die Ausgabe kann vollständig in den Chat kopiert werden. Das Diagnosekommando zeigt bewusst keine Passwörter, Zugangstokens, Recovery-Codes oder Ticketinhalte.

---

## 2. Grundfunktion nach Update

### Kollegiumsseite

- privaten Kollegiumslink öffnen
- Startseite erscheint
- oberes Anker-Menü sichtbar:
  - KI-Assistent, falls aktiviert
  - Ticket aufgeben
  - FAQ
  - Ticketstatus
- Anker scrollen nur innerhalb derselben Seite
- dezente Farbcodierung der Bereiche sichtbar

### Adminbereich

- Anmeldung mit bisherigem System-Admin funktioniert
- Ticketliste lädt
- Statistik, FAQ, System und Passwort ändern sind erreichbar
- keine PHP-/SQL-Fehlermeldungen

---

## 3. Neues Supportticket

Ein neues Testticket anlegen.

Beispiel:

- Kategorie: Netzwerk/WLAN
- Gerät: Dienst-iPad
- Frage: „Mein Dienst-iPad verbindet sich nicht mehr mit dem Schul-WLAN.“

### Erwartung

- Ticket wird erfolgreich erstellt
- Ticketnummer wird angezeigt
- zusätzlich erscheint ein zufälliger Statuscode
- Statuscode sieht ungefähr so aus:
  - `7K4M-P9Q2`
- Schulkennung ist für die Statusabfrage nicht mehr nötig

Ticketnummer und Statuscode notieren.

---

## 4. Ticketstatus

Auf der Kollegiumsseite zu **Ticketstatus** wechseln.

### Test A

- richtige Ticketnummer
- richtiger Statuscode

Erwartung: Ticketstatus wird angezeigt.

### Test B

- richtige Ticketnummer
- falscher Statuscode

Erwartung: kein Ticket wird angezeigt.

### Test C – Code zurücksetzen

Im Admin-Ticket:

- **Neuen Statuscode erzeugen**
- neuen Code notieren

Danach:

- alter Code muss scheitern
- neuer Code muss funktionieren

---

## 5. Ticketbearbeitung

Im Admin:

- Priorität ändern
- Status auf **In Bearbeitung**
- interne Notiz hinzufügen
- Status auf **Rückfrage**
- anschließend Lösung als letzte interne Notiz eintragen
- Status auf **Erledigt**

### Erwartung

- alle Schnellaktionen funktionieren
- keine Meldung „ungültige Schnellaktion“
- öffentliche Statusabfrage spiegelt den neuen Status
- beim erstmaligen Erledigen entsteht automatisch ein FAQ-Entwurf

---

## 6. FAQ-Autopilot

Admin → FAQ öffnen.

Beim eben erledigten Ticket prüfen:

- Entwurf vorhanden
- Frage wurde automatisch formuliert
- letzte interne Lösungsnotiz steht als Antwortentwurf bereit
- Empfehlung ist sichtbar:
  - Neue FAQ sinnvoll
  - Bestehende FAQ wahrscheinlich
  - Bitte kurz prüfen

Frage und Antwort kontrollieren.

Dann veröffentlichen.

### Erwartung

- FAQ erscheint auf der Kollegiumsseite
- veröffentlichte FAQ zeigt Ticket-Häufigkeit im Adminbereich
- keine internen Notizen werden ohne Freigabe veröffentlicht

---

## 7. Dublettenprüfung

Zweites Ticket mit sehr ähnlichem WLAN-Problem erstellen und lösen.

### Erwartung

Der FAQ-Autopilot sollte die bestehende FAQ erkennen.

Im Idealfall:

- **Bestehende FAQ wahrscheinlich**
- ähnliche FAQ wird angezeigt
- Ähnlichkeitswert sichtbar
- **Bestehende FAQ aktualisieren** möglich

Nach Zusammenführen:

- keine zweite Dublette nötig
- Ticketzähler der FAQ steigt

---

## 7a. Synergetische FAQ-Verknüpfung

Bei einem ähnlichen Ticket mit bereits vorhandener FAQ:

### Test A – nur verknüpfen

- **Mit bestehender FAQ verknüpfen**

Erwartung:

- bestehende öffentliche Frage bleibt unverändert
- bestehende öffentliche Antwort bleibt unverändert
- Ticketzähler steigt
- interne Suchbegriffe steigen

### Test B – echte Ergänzung

Bei einem weiteren Ticket mit zusätzlicher allgemeiner Lösungsinformation:

- **Bestehende FAQ inhaltlich ergänzen** öffnen
- Altbestand und neuen Supportfall vergleichen
- gemeinsame Fassung prüfen/bearbeiten
- **Geprüfte Ergänzung speichern**

Erwartung:

- alte Informationen bleiben erhalten
- neue allgemeine Information wird ergänzt
- vorherige Fassung wird als Revision gezählt

### Test C – manuelle Bearbeitung

Unter veröffentlichter FAQ:

- **FAQ bearbeiten**

Erwartung:

- Frage/Antwort können korrigiert werden
- vorherige Fassung wird als Revision gesichert

---

## 8. FAQ-Self-Service vor Ticketabgabe

Neues Supportformular öffnen.

- passende Kategorie wählen
- „Dienst-iPad“ eintragen
- ähnliches WLAN-Problem formulieren

### Erwartung

Noch während der Eingabe erscheinen bis zu drei passende FAQ-Lösungen.

Eine Lösung öffnen.

Dann:

- **Das hat geholfen – kein Ticket nötig**

testen.

Danach alternativ ein weiteres Mal ein Ticket trotz FAQ abschicken.

---

## 9. Statistik

Admin → Statistik.

Prüfen:

### Tickets

- heute
- 7 Tage
- 30 Tage
- gesamt
- offen
- erledigt

### Assistent

Falls aktiviert:

- Inline-Chat
- Sprechblase
- extern geöffnet
- Ticket nach Assistent-Nutzung

### FAQ

- FAQ auf Startseite geöffnet
- FAQ-Vorschlag geöffnet
- „Hat geholfen“
- Ticket nach FAQ-Nutzung

Wichtig: Zahlen müssen plausibel sein, nicht zwingend exakt bei mehrfachen Testklicks.

---

## 10. Admin-Konten

Admin → System → Administratorkonten.

### Ticket-Admin anlegen

- Anzeigename
- Benutzername
- Rolle Ticket-Admin
- Startpasswort mit mindestens 14 Zeichen

Abmelden und mit neuem Konto anmelden.

### Erwartung

- sofortiger Zwang zu **Passwort ändern**
- nach Passwortwechsel normaler Adminzugang
- Tickets, FAQ und Statistik erreichbar
- Systembereich nicht zugänglich

### System-Admin testen

Optional zweites System-Admin-Konto anlegen.

Testen:

- Rolle ändern
- Konto sperren
- Konto reaktivieren
- Startpasswort zurücksetzen

Schutztest:

- letzter aktiver System-Admin darf nicht entfernt werden

---

## 11. Passwortwechsel eigenes Konto

Admin → Passwort ändern.

Testen:

- falsches aktuelles Passwort → Ablehnung
- unterschiedliche neue Passwörter → Ablehnung
- weniger als 14 Zeichen → Ablehnung
- gültiger Wechsel → Erfolg
- laufende Sitzung bleibt gültig

---

## 12. Backups

Admin → System → Backups.

Prüfen:

- USB-Backup eingerichtet
- Medium angeschlossen
- Verschlüsselung aktiv
- letzte Sicherung sichtbar

Dann:

- **Backup jetzt erstellen**

### Erwartung

- Sicherung erfolgreich
- neues verschlüsseltes Archiv
- Zeitpunkt aktualisiert
- Dateigröße sichtbar

Danach USB testweise entfernen und Seite neu laden.

Erwartung:

- Medium wird als nicht angeschlossen angezeigt
- manuelles Backup nicht startbar

---

## 13. Updateprüfung

Admin → System → Updates.

- installierte Version sichtbar
- **Jetzt nach Updates suchen**

### Erwartung

Solange kein stabiles GitHub-Release veröffentlicht ist:

- keine neuere stabile Version
- keine Fehlermeldung
- automatische Installation wird noch nicht angeboten

Timer:

`schulit-update-check.timer`

muss installiert und aktiviert sein.

---

## 14. Cloudflare Tunnel – optional erst ganz zum Schluss

Nur durchführen, wenn Domain/Subdomain und Cloudflare-Konto bereit sind.

Admin → System → Domain / Cloudflare Tunnel.

Cloudflare:

- Tunnel erstellen
- Published Application:
  - Hostname z. B. `support.schule.de`
  - Service `http://localhost:8081`

Dann im Ticketsystem:

- Hostname eintragen
- Tunnel-Token oder kompletten Cloudflare-Installationsbefehl einfügen
- aktivieren
- Verbindung testen

### Erwartung

- Tunnel-Dienst aktiv
- öffentliche HTTPS-URL erreichbar
- keine Router-Portfreigabe erforderlich
- Kollegiumslink mit Zugangstoken funktioniert

Danach über Mobilfunk statt WLAN testen.

---

## 15. Mobil / Cross-Browser

Mindestens:

### Android

- Chrome
- Kollegiumsseite
- Ticketformular
- FAQ
- Statusabfrage
- Admin-Ticketkarten

### iPhone/iPad

- Safari
- Anchor-Menü
- Formulare
- FAQ-Aufklapper
- eingebetteter Assistent, falls aktiviert

### Desktop

- Chrome/Edge unter Windows
- Safari/Chrome unter macOS, falls verfügbar
- alternativ Firefox/Linux

Besonders prüfen:

- kein horizontales Überlaufen
- Buttons vollständig sichtbar
- Admin-Ticketkarten auf schmalem Display
- Statistik bleibt nutzbar
- Sprechblase verdeckt keine wichtigen Bedienelemente

---

## 16. Archiv

Testticket archivieren.

### Erwartung

- verschwindet aus aktiver Liste
- erscheint im Archiv
- kann wiederhergestellt werden
- mobile Darstellung bleibt sauber

---

## 17. Restore-Rehearsal

Nur mit bekannt funktionierendem Backupmedium und Recovery-Code.

Nicht-destruktiven Restore-Test verwenden.

Prüfen:

- SHA-256
- Recovery-Code
- age-Entschlüsselung
- Datenbankdump
- Konfiguration
- Zugriffstoken optional/kompatibel
- Live-System wird nicht verändert

Der vollständige Restore auf frischem Bootmedium bleibt ein separater Katastrophentest.

---

## 18. Abschlusskriterien für 0.5

Version 0.5 gilt auf Raspberry Pi 4 als hardwareseitig bestätigt, wenn:

- Installer ohne Fehler durchläuft
- Migrationen 001–006 eingetragen sind
- öffentliche Ticketanlage funktioniert
- sichere Statusabfrage funktioniert
- Admin-Workflow funktioniert
- FAQ-Autopilot funktioniert
- FAQ-Self-Service funktioniert
- Statistik plausibel ist
- Adminrollen funktionieren
- Backup funktioniert
- Updateprüfung funktioniert
- Mobile Grundfunktionen funktionieren

Cloudflare und vollständiger Fresh-Media-Restore können separat als eigene Freigabepunkte dokumentiert werden.
