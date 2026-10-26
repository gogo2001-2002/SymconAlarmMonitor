AlarmMonitor – Modulversion 1.58

# IP-Symcon AlarmMonitor 1.57

Überwacht Variablen anhand von Bedingungen, Vergleichswerten und Verzögerungen. Jede Regel kann aktiviert/deaktiviert werden. Push, Mail, Anruf, Echo und Nachrichten lassen sich getrennt für Erstmeldung und Eskalation steuern. Unterstützt werden bis zu vier Push-Visualisierungen und vier EchoRemote-Instanzen je Regel.

## Vergleichswert
Ist eine Vergleichsvariable ausgewählt, wird ausschließlich deren aktueller Wert verwendet. Andernfalls gilt der feste Vergleichswert.

## Eskalation
Bei Eskalationsmeldungen wird „N. Eskalation: “ vor den Alarmtext gesetzt. Die Nummer entspricht dem aktuellen Eskalationszähler und steht auch im E-Mail-Betreff. Die Eskalationsvariable ist eine Integer-Variable und zählt die gesendeten Eskalationen. Bei jeder Eskalation wird der Zähler um eins erhöht; beim Ende des Alarms oder Deaktivieren der Regel wird er auf 0 zurückgesetzt.

## Bedingungen
Unterstützt werden Wertvergleiche sowie „Variable verändert“ und „Variable aktualisiert“. Verzögerung und Eskalationsintervall werden je Regel konfiguriert. E-Mail-Adressen und Telefonnummern kommasepariert eingeben.


## Standardwerte in neuen Regeln (Version 1.55)
Bei neu angelegten Regeln sind die Phasenflags „Erstmeldung“ und „Eskalation“ für Push, Mail, Anruf und Echo standardmäßig deaktiviert. Benachrichtigungen werden erst ausgeführt, wenn die jeweilige Phase ausdrücklich aktiviert wird.


## Eskalationszähler (Version 1.55)
Die Variable „Eskalation“ ist ein Integer-Zähler. Sie wird bei jeder ausgelösten Eskalationsbenachrichtigung um eins erhöht und beim Zurücksetzen des Alarms auf 0 gesetzt. Vorhandene boolesche Eskalationsvariablen werden automatisch durch Integer-Variablen ersetzt.


## Nachrichtenmodul

Für jede Regel kann eine Nachrichteninstanz ausgewählt werden. „Erstmeldung“ und „Eskalation“ werden getrennt aktiviert. Die Auswahl zeigt ausschließlich Instanzen des Moduls **MessageCenter** (Modul-ID `{A1C92D44-2F7A-4BF4-B1E6-9D0F8A6C77E2}`).

Die Alarmmeldung wird als mehrzeiliger Text gespeichert und enthält Meldung, überwachte Variable, Bedingung, Verzögerung, Zeitpunkt und aktuellen Wert. Bei Eskalationen wird – wie bei den anderen Alarmkanälen – der Eskalationspräfix ergänzt. Bei Ende eines aktiven Alarms wird automatisch eine weitere Nachricht an dieselbe Instanz gesendet. Ihr Titel beginnt mit „Behoben:“ statt „Meldung:“. Das gilt für jede konfigurierte Nachrichteninstanz unabhängig davon, ob Erstmeldung oder Eskalation aktiviert sind. Die Nachrichteninstanz verwaltet anschließend Quittierung und Aufbewahrung. Zusätzlich sendet der AlarmMonitor den Alarmzustand als strukturierte Metadaten mit: Erstmeldungen werden als `initial`, Eskalationen als `escalation` und aufgehobene Alarme als `resolved` gekennzeichnet. Die Visu markiert diese Nachrichten gelb, rot bzw. grün.

Voraussetzung ist eine installierte und aktive Instanz des eigenständigen [Nachrichten-Moduls](https://github.com/gogo2001-2002/Nachrichten).
