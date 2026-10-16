AlarmMonitor – Modulversion 1.53

# IP-Symcon AlarmMonitor 1.53

Überwacht Variablen anhand von Bedingungen, Vergleichswerten und Verzögerungen. Jede Regel kann aktiviert/deaktiviert werden. Push, Mail, Anruf und Echo lassen sich getrennt für Erstmeldung und Eskalation steuern. Unterstützt werden bis zu vier Push-Visualisierungen und vier EchoRemote-Instanzen je Regel.

## Vergleichswert
Ist eine Vergleichsvariable ausgewählt, wird ausschließlich deren aktueller Wert verwendet. Andernfalls gilt der feste Vergleichswert.

## Eskalation
Bei Eskalationsmeldungen wird „Eskalation: “ vor den Alarmtext gesetzt. Die Eskalationsvariable ist eine Integer-Variable und zählt die gesendeten Eskalationen. Bei jeder Eskalation wird der Zähler um eins erhöht; beim Ende des Alarms oder Deaktivieren der Regel wird er auf 0 zurückgesetzt.

## Bedingungen
Unterstützt werden Wertvergleiche sowie „Variable verändert“ und „Variable aktualisiert“. Verzögerung und Eskalationsintervall werden je Regel konfiguriert. E-Mail-Adressen und Telefonnummern kommasepariert eingeben.


## Standardwerte in neuen Regeln (Version 1.53)
Bei neu angelegten Regeln sind die Phasenflags „Erstmeldung“ und „Eskalation“ für Push, Mail, Anruf und Echo standardmäßig deaktiviert. Benachrichtigungen werden erst ausgeführt, wenn die jeweilige Phase ausdrücklich aktiviert wird.


## Eskalationszähler (Version 1.53)
Die Variable „Eskalation“ ist ein Integer-Zähler. Sie wird bei jeder ausgelösten Eskalationsbenachrichtigung um eins erhöht und beim Zurücksetzen des Alarms auf 0 gesetzt. Vorhandene boolesche Eskalationsvariablen werden automatisch durch Integer-Variablen ersetzt.
