AlarmMonitor – Modulversion 1.49

# IP-Symcon AlarmMonitor 1.49

## Vergleich mit einer anderen Variable
Für jede Regel kann der Vergleichswert weiterhin als fester Wert eingetragen werden. Alternativ kann eine **Vergleichsvariable** ausgewählt werden. Sobald eine Vergleichsvariable angegeben ist, wird ausschließlich deren aktueller Wert bei jeder Auswertung verwendet; der feste Vergleichswert wird ignoriert. Ist keine Vergleichsvariable ausgewählt, wird ausschließlich der feste Vergleichswert verwendet. Ein zusätzlicher Umschalter ist nicht erforderlich.

- Numerische Variablen können gegen numerische Variablen verglichen werden. Boolesche Variablen werden als 0/1 interpretiert.
- Bei String-Variablen sind wie bisher nur „Ist gleich“ und „Ist nicht gleich“ zulässig.
- Fehlt die ausgewählte Vergleichsvariable oder wird sie gelöscht, wird die Bedingung nicht erfüllt. Das Modul fällt nicht automatisch auf den Festwert zurück.
- Änderungen der Vergleichsvariable werden ebenfalls zur Neuauswertung berücksichtigt.

## Weitere Funktionen
Die bisherigen Funktionen aus Version 1.41 bleiben erhalten: zentrale Regelaktivierung, Erstmeldung/Eskalation je Ausgabeweg, bis zu vier Push-Visualisierungen und vier EchoRemote-Instanzen, E-Mail/Telefon-Benachrichtigung, Eskalationspräfix sowie die Bedingungen „Variable verändert“ und „Variable aktualisiert“.

## Prüfung
PHP-Syntax, JSON-Syntax und ZIP-Integrität wurden lokal geprüft. Ein Laufzeittest in einer IP-Symcon-Instanz wurde nicht durchgeführt.


## Vergleichsquelle strikt umschalten (Version 1.43)
- Schalter **aus**: Es wird ausschließlich der eingetragene feste Vergleichswert verwendet.
- Schalter **an**: Es wird ausschließlich der aktuelle Wert der ausgewählten Vergleichsvariable verwendet; der feste Vergleichswert wird ignoriert.
- Ist im Variablenmodus keine gültige Vergleichsvariable vorhanden, wird die Bedingung als nicht erfüllt behandelt. Es gibt keinen Rückfall auf den Festwert. Das Debug-Protokoll erhält pro Regel und Fehlerzustand nur einen Hinweis, damit das 1-Sekunden-Auswertungsintervall das Protokoll nicht überfüllt.
- Sobald die Vergleichsvariable wieder gültig ist oder der Variablenmodus ausgeschaltet wird, wird der Warnstatus zurückgesetzt.


## Standard-Vergleichsquelle (Version 1.44)
- Neue Regeln verwenden standardmäßig den festen Vergleichswert.
- Die Vergleichsquelle ergibt sich ausschließlich daraus, ob eine Vergleichsvariable ausgewählt ist.
- Bestehende Regeln mit aktiviertem Variablenmodus behalten diese Einstellung; fehlende Felder werden auf `UseCompareVariable = false` und `CompareVariableID = 0` gesetzt.


## Hinweis im Detailfenster (Version 1.45)
Nach dem Hinweis „Wenn ‚Vergleichswert aus Variable‘ aktiviert ist, wird der feste Vergleichswert ignoriert.“ ist eine zusätzliche Leerzeile vorgesehen, damit der Hinweis optisch von den folgenden Feldern getrennt ist.

## Beschreibung aktualisiert (Version 1.46)
Die Modulbeschreibung dokumentiert jetzt auch die letzte Layoutänderung: Zwischen dem Hinweis zur Umschaltung der Vergleichsquelle und den folgenden Einstellungen befindet sich eine zusätzliche Leerzeile. Die Vergleichslogik bleibt unverändert: Ist eine Vergleichsvariable ausgewählt, gilt ausschließlich deren aktueller Wert; ohne Auswahl gilt ausschließlich der feste Vergleichswert. Der Push-Ton wird im Detailfenster in einer eigenen Zeile unter den Phasenflags angezeigt.


**Layoutänderung:** Das Feld „Push-Ton“ steht im Detailfenster jetzt in einer eigenen Zeile unterhalb der Auswahl „Erstmeldung“ und „Eskalation“.


## Änderungen in Version 1.48
- Das Feld „Push-Ton“ befindet sich im Detailfenster in einer eigenen Zeile unterhalb der Flags „Erstmeldung“ und „Eskalation“.
- Der Schalter zur Wahl der Vergleichsquelle wurde entfernt. Ist eine Vergleichsvariable ausgewählt, wird ausschließlich ihr aktueller Wert verwendet. Ist keine Vergleichsvariable ausgewählt, wird ausschließlich der feste Vergleichswert verwendet.


## Version 1.49 – Eskalationsstatus

Für jede konfigurierte Bedingung wird zusätzlich zur bisherigen Alarmvariable eine boolesche Variable für den Eskalationsstatus angelegt. Ihr Name beginnt mit dem Alarmtext, gefolgt von `Eskalation`, danach folgen Variablen-ID, Bedingung und Verzögerung wie bei der Alarmvariable. Die Variable wird auf `true` gesetzt, sobald für die Bedingung die erste Eskalationsbenachrichtigung ausgelöst wird. Sobald der Alarm nicht mehr aktiv ist oder die Regel deaktiviert wird, wird sie auf `false` zurückgesetzt.
