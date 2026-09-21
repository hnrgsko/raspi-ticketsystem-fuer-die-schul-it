# Phase 5d – Backupverwaltung im Adminbereich

## Ziel

Der laufende Backupbetrieb soll aus dem normalen Systembereich kontrollierbar sein.

## Admin → System → Backups

Angezeigt werden:

- USB-Backup eingerichtet / nicht eingerichtet
- Backupmedium angeschlossen / nicht angeschlossen
- Verschlüsselung aktiv / nicht aktiv
- Zeitpunkt der letzten Sicherung
- Dateiname und Größe des letzten verschlüsselten Archivs

Bei vollständig eingerichtetem und angeschlossenem Backupmedium kann ein System-Admin:

**Backup jetzt erstellen**

verwenden.

Der bestehende tägliche systemd-Backup-Timer bleibt zusätzlich aktiv.

## Initiale Einrichtung

Die einmalige Registrierung des USB-Datenträgers und Aktivierung der Recovery-Code-geschützten Verschlüsselung bleiben Teil der Ersteinrichtung.

Danach ist der normale Betrieb vollständig im Systembereich sichtbar.

## Restore-Härtung

Zusätzlich korrigiert:

- wiederhergestellte Upload-Verzeichnisse gehören wieder dem Webprozess
- Verzeichnis- und Dateirechte werden nach Restore normalisiert
- alte Backups ohne Kollegiums-Zugangstoken bleiben wiederherstellbar
- fehlt der Token, wird lokal ein neuer sicherer Token erzeugt
- ein Restore-Test zeigt, ob ein Backup bereits einen Zugangstoken enthält

## Realtest später

1. USB-Medium angeschlossen.
2. System → Backups öffnen.
3. Status prüfen.
4. manuelles Backup starten.
5. neues Archiv auf USB prüfen.
6. USB entfernen und Status prüfen.
7. Restore-Rehearsal erneut durchführen.

## Hardwaretest Raspberry Pi 4

Am 21.09.2026 erfolgreich getestet:
- Admin → System → Backups zeigt den vorhandenen Backup-Status nach Wiederherstellung des setupd-Socket-Zugriffs.
- „Backup jetzt erstellen“ erzeugt eine aktuelle Sicherung.
- Die Anzeige „Letzte Sicherung“ wird anschließend aktualisiert.

Hinweis: Der beim Neustart gefundene Rechtefehler für `/run/schulit` wurde separat behoben; der dauerhafte Reboot-Fix muss noch durch einen weiteren Neustart auf Hardware bestätigt werden.
