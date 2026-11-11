# Clip-Warteschlange: Reservierungen und Aufnahme

Dieser Zwischenstand gehört zu Schritt 2. Er enthält Datenmodell und interne
Aufnahme-/Reservierungslogik, noch keine freigeschaltete Clipfunktion.
Die Migration wurde in isolierten MySQL-Testtabellen ausgeführt, nicht auf den
laufenden Anwendungstabellen. Öffentliche Aufnahme-Endpunkte, Worker,
Verbindung zur Browseroberfläche und automatische Bereinigung fehlen noch.

`ClipTransferQueue` serialisiert Aufnahme, Verteilung und Zustandswechsel über
eine gemeinsame Datenbank-Zeilensperre. Zwei unabhängige MySQL-Verbindungen
wurden im Test gleichzeitig gestartet: Nur ein Auftrag erhielt den einzelnen
Oracle-Platz, der zweite blieb wartend. Zusätzliche Konten und globale Grenzen
werden ebenfalls unter derselben Sperre berücksichtigt.

Die aktuelle Oracle-Anbindung verwendet das eingerichtete Konto. Ein Auftrag
behält seine Konto-ID. Bei einem Bucketwechsel werden neue Aufträge dem neuen
Bucket zugeordnet; bestehende Reservierungen behalten den bisherigen,
verschlüsselt gespeicherten Zugang für die spätere Bereinigung. Zugänge und
interne Providerreferenzen erscheinen nicht in Modell-JSON oder Statusantworten.

Die Defaults sind drei aktive Vorgänge insgesamt, einer je Konto sowie einer
aktiv und einer wartend je Nutzer. Fünf neue Aufträge je Anwendungskalendertag
sind erlaubt. Ein wiederholter Request-Key mit identischen Daten gibt denselben
Auftrag zurück; abweichende Daten mit demselben Key werden abgewiesen.
Parallelität, Tagesgrenze und reservierbare Bytes je Konto werden zentral aus
`config/clip_staging.php` gelesen. Das Speichern der Oracle-Verbindung und jeder
Workerlauf synchronisieren genau das aktuell konfigurierte Konto in die
Warteschlange. Eine unvollständige Verbindung pausiert nur neue Aufnahmen;
verschlüsselte Kontosnapshots offener Aufträge bleiben für deren Bereinigung
erhalten. API-/Monatsverbrauch und dessen Budgets sind noch nicht implementiert.

Die Belegungsreservierung umfasst die doppelte exakte Dateigröße für Incoming-
und Handoff-Kopie. Auch ein abgebrochener Auftrag behält diese Reservierung,
solange ein Uploadrecht noch gültig sein könnte. Das Uploadfenster beträgt fünf
Minuten, mit weiteren 15 Minuten für eine bereits begonnene Übertragung.
Ein späterer Worker darf die Reservierung erst nach bestätigter abschließender
Bereinigung freigeben. Dieser Stand gibt sie noch nicht automatisch frei und
behauptet damit auch keine geprüfte 24-Stunden-Bereinigung.

Wartende Aufträge werden nach einer Datenbanksequenz verteilt, unabhängig von
gleichen Zeitstempeln. Ein bereits aktiver Auftrag desselben Nutzers blockiert
keinen anderen aufnahmefähigen Nutzer. Nicht verfügbare Konten oder fehlende
Kapazität lassen Aufträge warten. Abbruch und Uploadbestätigung prüfen den
Besitzer. Doppelte Uploadbestätigungen setzen spätere Zustände nicht zurück.
Der aufrufende Controller muss zusätzlich den Zugriff auf das Originalvideo
prüfen, bevor er den internen Service aufruft.

Die MySQL-Prüfung nutzt ausschließlich zufällig präfixierte Fixture-Tabellen,
testet echte konkurrierende Schreiber und entfernt ihre Tabellen im Anschluss:

```sh
node tools/test/clip-transfer-queue.mjs
```

Es werden keine externen Anfragen oder Uploads ausgelöst. Ein Migrationsrollback
verweigert das Entfernen von Tabellen mit noch offenen Speicherreservierungen.
