# Externe Mediendienste im Adminbereich

Die freigegebene Einordnung ist **Einstellungen → Speicher & CDN → Externe
Mediendienste**. Zu diesem Bereich gehören Directupload, Fastpic, Postimages,
Oracle-Clip-Zwischenspeicher und das zentrale Odysee-Publishingkonto. Das sind
Verbindungen zu externen Anbietern; lokale Videodatei-Uploads bleiben deaktiviert.

## Speicherung und Änderungen

Administratoren können Zugangsdaten ersetzen oder ausdrücklich entfernen.
Leere Schlüsselfelder erhalten den bisherigen Wert. Die API liefert nur leere
Schlüsselfelder und Kennzeichen, ob ein Wert vorhanden ist, niemals den
gespeicherten Schlüssel selbst. Nicht sensible Felder wie Bucket, Region und
Kanal-ID bleiben sichtbar. Moderatoren und normale Konten haben keinen Zugriff.

Bestehende `.env`-Werte werden verwendet, bis für das jeweilige Feld eine
Admin-Änderung gespeichert wird. Eine ausdrücklich gespeicherte Entfernung
unterdrückt auch den alten `.env`-Wert. Die `.env` wird nicht umgeschrieben.
Dateigrößen, feste Anbieter-Endpunkte und die Reihenfolge der Imagehoster bleiben
unverändert. Die vorhandenen lokalen Uploadbudgets lassen sich je Imagehoster
anpassen; sie sind keine vom Anbieter zugesagten Kontingente.

Admin-Änderungen liegen verschlüsselt in
`storage/app/private/media-provider-settings.enc`, mit Dateimodus 0600 und
atomarer, gesperrter Aktualisierung. Dafür wird Laravels bestehender
Anwendungsschlüssel verwendet. Sichere diese private Datei und den getrennt
geschützten Anwendungsschlüssel bei einem Umzug; beides bleibt außerhalb von Git.
Beschädigte Verschlüsselung führt zu einer Fehlermeldung statt zum stillen
Reaktivieren eines alten Umgebungsschlüssels. Neue Vorgänge lesen die
gespeicherten Änderungen ohne Containerneustart.

## Odysee-Einrichtung

Die Admin-Anmeldung über HTTPS sendet E-Mail und Passwort ausschließlich zum
offiziellen Odysee-Login. Das Passwort wird nicht gespeichert. Der neue
Kontotoken bleibt serverseitig verschlüsselt; der Browser erhält eine auf fünf
Minuten begrenzte Auswahlsitzung mit den eigenen Kanälen. Diese Sitzung gehört
zum anmeldenden Administrator und kann nur einen seiner Kanäle übernehmen.
Erst das Speichern übernimmt Token und Kanal gemeinsam. Bei einem Fehler bleibt
die gespeicherte Verbindung erhalten.

Die CLI-Einrichtung `php artisan crozztube:clips:publisher-setup` verwendet ab
diesem Stand dieselbe verschlüsselte Einstellungsverwaltung. Bereits früher in
der `.env` eingerichtete Zugänge bleiben als Ausgangskonfiguration gültig.

## Begrenzte Tests

- Oracle: genau ein authentifizierter Bucket-HEAD-Aufruf. Das bestätigt Zugang,
  nicht Browser-CORS oder den vollständigen Clip-Ablauf.
- Odysee: authentifizierte Kanalliste und Zugehörigkeit des gewählten Kanals;
  keine Veröffentlichung oder Videodaten.
- Imagehoster: ausdrücklich angeforderter Upload eines synthetischen
  Ein-Pixel-Testbilds. Es werden keine Nutzerbilder verwendet. Diese Aktion
  verbraucht einen regulären Uploadversuch im lokalen Anbieterbudget.

## Implementierungsstand

Die verschlüsselte Speicherung, gemeinsamen Laufzeit-Einstellungen und
authentifizierten Admin-Endpunkte sind implementiert und werden separat
geprüft. Die Oberfläche mit den eingebetteten Anbieter-Anleitungen ist noch in
Arbeit. Schritt 2 bleibt offen, bis auch Warteschlange, Browser-Upload,
automatische Odysee-Übernahme und Bereinigung tatsächlich abgenommen sind.
