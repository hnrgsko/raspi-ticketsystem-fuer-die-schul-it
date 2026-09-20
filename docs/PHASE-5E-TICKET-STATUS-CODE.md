# Phase 5e – Sicherer Ticketstatus

## Problem

Die bisherige Kombination aus Ticketnummer und Schulkennung war für einen später öffentlich erreichbaren Dienst nicht ausreichend geheim. Eine Schulkennung ist kein Passwort und Ticketnummern sind grundsätzlich erratbar.

## Neue Lösung

Jedes neue Ticket erhält zusätzlich einen kurzen, zufälligen Statuscode.

Beispiel:

`7K4M-P9Q2`

Für die Statusabfrage werden benötigt:

- Ticketnummer
- Statuscode

Die Schulkennung wird dafür nicht mehr verwendet.

## Speicherung

Der Klartext-Statuscode:

- wird beim Erstellen genau einmal angezeigt
- wird nicht in der Datenbank gespeichert

Gespeichert wird ausschließlich:

`SHA-256(Statuscode)`

Der neue Statuscode besteht aus acht gut unterscheidbaren Zeichen und besitzt knapp 40 Bit Zufallsentropie. Zeichen wie `0/O` und `1/I/L` werden bewusst nicht verwendet.

## Verlorener Statuscode

Im Ticketdetail kann ein Admin:

**Neuen Statuscode erzeugen**

Der neue Code wird einmal angezeigt.

Der vorherige Statuscode wird unmittelbar ungültig.

## Bestehende Entwicklungstickets

Beim Schema-Upgrade erhalten bestehende Tickets einen unbekannten Zufallswert als Hash. Dadurch bleibt kein alter, vorhersagbarer Statuszugang offen.

Soll ein vorhandenes Testticket weiter über die Kollegiumsseite abgefragt werden, erzeugt ein Admin im Ticketdetail einen neuen Statuscode.

## Realtest später

1. neues Ticket erstellen
2. Ticketnummer + Statuscode notieren
3. erfolgreiche Statusabfrage
4. falschen Code testen
5. neuen Code im Admin erzeugen
6. alten Code erneut testen → muss scheitern
7. neuen Code testen → muss funktionieren
