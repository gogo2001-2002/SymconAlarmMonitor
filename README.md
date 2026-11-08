AlarmMonitor – Modulversion 1.64

# IP-Symcon AlarmMonitor 1.64

Überwacht Variablen anhand von Bedingungen, Vergleichswerten und Verzögerungen. Jede Regel kann aktiviert/deaktiviert werden. Push, WebPush, Mail, Anruf und Echo lassen sich getrennt für Erstmeldung und Eskalation steuern. Unterstützt werden bis zu vier Push-Visualisierungen und vier EchoRemote-Instanzen je Regel sowie automatischer WebPush an alle registrierten Geräte.

## Vergleichswert
Ist eine Vergleichsvariable ausgewählt, wird ausschließlich deren aktueller Wert verwendet. Andernfalls gilt der feste Vergleichswert.

## Eskalation
Bei Eskalationsmeldungen wird „N. Eskalation: “ vor den Alarmtext gesetzt. Die Nummer entspricht dem aktuellen Eskalationszähler und steht auch im E-Mail-Betreff. Die Eskalationsvariable ist eine Integer-Variable und zählt die gesendeten Eskalationen. Bei jeder Eskalation wird der Zähler um eins erhöht; beim Ende des Alarms oder Deaktivieren der Regel wird er auf 0 zurückgesetzt.

## Bedingungen
Unterstützt werden Wertvergleiche sowie „Variable verändert“ und „Variable aktualisiert“. Verzögerung und Eskalationsintervall werden je Regel konfiguriert. E-Mail-Adressen und Telefonnummern kommasepariert eingeben.


## Standardwerte in neuen Regeln (Version 1.55)
Bei neu angelegten Regeln sind die Phasenflags „Erstmeldung“ und „Eskalation“ für alle Alarmkanäle standardmäßig deaktiviert. Benachrichtigungen werden erst ausgeführt, wenn die jeweilige Phase ausdrücklich aktiviert wird.


## Eskalationszähler (Version 1.55)
Die Variable „Eskalation“ ist ein Integer-Zähler. Sie wird bei jeder ausgelösten Eskalationsbenachrichtigung um eins erhöht und beim Zurücksetzen des Alarms auf 0 gesetzt. Vorhandene boolesche Eskalationsvariablen werden automatisch durch Integer-Variablen ersetzt.


## WebPush (Version 1.59 / 1.60)
Für jede Regel können WebPush-Benachrichtigungen unabhängig für „Erstmeldung“ und „Eskalation“ aktiviert werden. Die Nachrichten werden browser- und smartphoneübergreifend an alle registrierten Handys gesendet (z.B. iPhone PWA oder Android).

- **Ziel**: Das Modul ermittelt standardmäßig automatisch das WebPush-Hilfsskript (`WebPushHelper` / ID `34007`). Alternativ kann global oder pro Regel ein spezifisches WebPush-Skript oder eine WebPush-Instanz ausgewählt werden.
- **Benachrichtigungsinhalt**: Enthält Alarmtext, überwachte Variable, aktuellen Messwert und Auslösezeitpunkt. Bei Eskalationen wird der Eskalationspräfix vorangestellt.
- **Klick-Aktion**: Ein Klick oder Antippen der Push-Nachricht auf dem Mobilgerät öffnet direkt die Visu auf der Seite „Nachrichten“ (`#system-messages`).


## Alarmbehebung / Entwarnung (Version 1.60, kanalbezogen konfigurierbar ab 1.64)
Für jeden Benachrichtigungskanal lässt sich **„Behoben melden“** unabhängig aktivieren: Push, E-Mail, Anruf, Echo und WebPush. Neue Regeln und bestehende Regeln nach der Migration haben die Optionen standardmäßig aktiviert. Bei Alarmende wird ein Kanal nur benachrichtigt, wenn seine eigene Option aktiv ist. Für Anrufe wird dabei die TelefonansageDirekt-Instanz mit „Entwarnung: [Text] ist behoben.“ aufgerufen.
