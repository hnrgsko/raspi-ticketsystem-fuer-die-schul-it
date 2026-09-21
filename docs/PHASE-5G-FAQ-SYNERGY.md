# Phase 5g – Synergetische FAQ-Zusammenführung

## Problem

Die bisherige Aktion **Bestehende FAQ aktualisieren** ersetzte Frage und Antwort der vorhandenen FAQ vollständig durch den neuen Ticketentwurf.

Das ist für einen wachsenden Wissensbestand zu aggressiv: Eine gute vorhandene FAQ kann dadurch Informationen verlieren.

## Neue Strategie

Ein ähnlicher Supportfall bietet nun drei klar getrennte Wege.

### 1. Mit bestehender FAQ verknüpfen

Standardfall, wenn die vorhandene FAQ das Problem bereits ausreichend beantwortet.

Dabei:

- bleibt die öffentliche Frage unverändert
- bleibt die öffentliche Antwort unverändert
- wird das neue Ticket mit der FAQ verknüpft
- steigt der Ticketzähler
- neue Formulierungen werden ausschließlich als interne Suchbegriffe gespeichert

Dadurch lernt die lokale Suche weitere Formulierungen kennen, ohne die sichtbare FAQ aufzublähen.

### 2. Bestehende FAQ inhaltlich ergänzen

Nur wenn der neue Supportfall wirklich zusätzliche allgemeine Informationen enthält.

Der Admin sieht vor dem Speichern nebeneinander:

- bisherige öffentliche FAQ
- neuen Supportfall

Darunter befindet sich eine bearbeitbare gemeinsame Fassung.

Standardmäßig:

- bleibt die bisherige Problemfrage erhalten
- bleibt die bisherige Antwort vollständig erhalten
- eine neue, abweichende Lösungsinformation wird als Ergänzung angehängt

Erst **Geprüfte Ergänzung speichern** verändert die öffentliche FAQ.

### 3. Trotzdem neue FAQ veröffentlichen

Wenn der neue Fall trotz Ähnlichkeit ein eigenständiges Problem ist.

## Interne Suchbegriffe

Migration 007 führt `faq_entry_search_terms` ein.

Gespeichert werden bei verknüpften Tickets insbesondere:

- automatisch formulierte Problemfrage
- Problembeschreibung
- Gerät/System
- Defektbezeichnung, falls vorhanden

Diese Begriffe sind nicht öffentlich sichtbar.

FAQ-Vorschläge und Dublettenerkennung berücksichtigen diese Begriffe zusätzlich. Dadurch kann eine FAQ bei immer mehr unterschiedlichen Nutzerformulierungen gefunden werden.

Bereits vorhandene Ticket-FAQ-Verknüpfungen werden beim Update aus den verknüpften Tickets nachträglich als Suchwissen übernommen.

## Versionshistorie

Migration 007 führt zusätzlich `faq_entry_revisions` ein.

Vor einer:

- inhaltlichen Ergänzung
- manuellen Bearbeitung

wird die bisherige Fassung intern gesichert.

Damit überschreiben spätere Änderungen den vorherigen Wissensstand nicht mehr spurlos.

## Manuelle Bearbeitung

Unter **Admin → FAQ → Veröffentlichte FAQ** besitzt jeder Eintrag nun **FAQ bearbeiten**.

Das dient auch dazu, eine versehentlich unpassend veränderte FAQ wieder sauber zu formulieren.

## Moderationsprinzip

Bei mittlerer Ähnlichkeit ist **Mit bestehender FAQ verknüpfen** der sichere Standard.

Eine öffentliche FAQ soll nur verändert werden, wenn der neue Supportfall tatsächlich zusätzliche allgemeine Information liefert.

Es findet weiterhin keine automatische Veröffentlichung und keine automatische inhaltliche Überschreibung statt.

## Test

1. Migration 007 anwenden.
2. Bestehende FAQ und ähnliches Ticket verwenden.
3. **Mit bestehender FAQ verknüpfen**.
4. Prüfen:
   - öffentliche FAQ unverändert
   - Ticketzähler steigt
   - interne Suchbegriffe steigen
5. Zweiten Testfall mit echter Zusatzinformation erzeugen.
6. **Bestehende FAQ inhaltlich ergänzen** öffnen.
7. Altbestand und neuen Fall vergleichen.
8. gemeinsame Fassung bearbeiten.
9. speichern.
10. prüfen, dass die bisherige Fassung als Revision gezählt wird.
