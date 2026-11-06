# Ausgangsstand vor den Community-Erweiterungen

Basis: `8797144` auf `main`, gesichtet am 2026-11-06. Der Sicherungsbranch
heißt `codex/viewer-community-features`. Dieser Ausgangsstand ist ein
**Arbeitsstand**, keine nachträgliche vollständige Abnahme aller Altänderungen.

## Erhaltene Themen

- Sprachpakete: persönliche Ansprache, Zusammenführung von Anredevarianten,
  Locale-Aliase, E-Mail-Standards und sprachgerechte dynamische Meldungen.
- Sprachwerkzeuge: Textinventur, Änderungsbatches und sprach-/textbezogene
  Prüfaufzeichnungen. Die vollständige sprachliche Kontextabnahme aller
  125 erhaltenen Pakete ist weiterhin offen; strukturelle Tests ersetzen sie nicht.
- Provider: Rumble, Odysee, BitChute und AUF1, automatische URL-Erkennung,
  Metadaten, Kapitel, Untertitel, Tonspuren und Import-Duplikatprüfung.
- Oberfläche: Startseite, Karten, Watch-Seite, Importdetails, Dropdowns und
  responsive Navigation einschließlich Fokus-/Scrollkorrekturen.
- Lokales HTTPS: mkcert-Setup, Dokumentation und gesonderte Browserprüfungen.
- Zugehörige PHP-, Node- und Browserprüfungen sowie die momentan vom
  Vite-Manifest referenzierten neuen Release-Dateien.

## Bewusste Bereinigung und Grenzen

- Ein bisher in Dokumentation und Testhilfen fest eingebettetes lokales
  Adminpasswort wurde entfernt. Authentifizierte Tests benötigen jetzt
  `TEST_PASSWORD` aus der lokalen Umgebung. Eine vorhandene lokale Kopie liegt
  nur in der ignorierten Datei `.env.harness.local` (Dateirechte 0600).
- Bereits in früheren Commits enthaltene Zugangsdaten werden durch diesen
  Commit nicht aus der Historie entfernt. Ihre Rotation ist gesondert zu behandeln.
- Frühere, unversionierte Vite-Chunks bleiben lokal für offene Browser-Tabs
  erhalten und werden ignoriert. Aktuelle Manifest-Dateien werden ausdrücklich
  hinzugefügt; vorhandene versionierte Release-Dateien werden nicht gelöscht.
- Runtime-Datenbanken, Adminanpassungen, Zertifikate, CA-Schlüssel,
  Zugangsschlüssel und private Umgebungsdateien gehören nicht zur Sicherung.
- Keine der neuen Funktionen aus den Schritten 2–12 wurde hier begonnen.

## Sicherungsprüfung

Vor Veröffentlichung: exakter Index-Scan mit Gitleaks, PHP-Syntaxprüfung,
vorhandene Node-Regressionen, Produktionsbuild und ausgewählte isolierte
PHP-/Browserprüfungen. Ergebnisse werden im Harness-Protokoll dokumentiert;
der Sicherungscommit ist ausdrücklich kein Ersatz für die offenen Fachabnahmen.

Erfolgreich ausgeführt für diesen Sicherungsstand:

- `git diff --cached --check` und Gitleaks 8.30.1 auf dem vollständigen Index.
  Nur eng begrenzte, geprüfte Fehlalarme für Tastaturcode und übersetzte
  Datenschutzprosa stehen in `.gitleaks.toml`.
- Syntaxprüfung aller 588 versionierten PHP-Dateien.
- `node --test tools/test/*.test.mjs`: 67 Tests bestanden.
- `npm run build`: erfolgreich, bestehender Hinweis auf große Bundles bleibt.
- Isolierte PHP-Prüfungen: 88 Lokalisierungs-, 51 Locale-Alias-, 16 Meldungs-
  und 33 Shorts-Prüfungen; außerdem Rumble, Odysee/Tonspuren, Kapitel/
  Duplikate/BitChute, Untertitel und 70 AUF1-Prüfungen ohne Fehler.
- Browser: 26 Prüfungen zu Viewer-Oberfläche, Watch-Menüs, Embed-Sicherheit
  und Import-Wiedergabe, mit einem Testworker und Chromium/Firefox/WebKit.

Die PHP-Prüfungen verwenden temporären Speicher, HTTP-Fakes beziehungsweise
zurückgerollte Datenbanktransaktionen. Browserprüfungen verwenden isolierte
Layout-Antworten und veröffentlichen keine Nutzerinhalte.
