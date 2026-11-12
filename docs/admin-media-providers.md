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

Für Oracle sind außerdem reservierbarer Speicher, global gleichzeitig aktive
Clip-Übertragungen, neue Clips je Person und Tag sowie neue Clips je Monat
einstellbar. Die Monatsgrenze zählt Aufnahmeversuche konservativ auch dann, wenn
sie später abgebrochen werden oder fehlschlagen. Änderungen gelten für neue
Aufnahmen; laufende Aufträge behalten ihren verschlüsselten Bucket-Snapshot.

Admin-Änderungen liegen verschlüsselt in
`storage/app/private/media-provider-settings.enc`, mit Dateimodus 0600 und
atomarer, gesperrter Aktualisierung. Dafür wird Laravels bestehender
Anwendungsschlüssel verwendet. Sichere diese private Datei und den getrennt
geschützten Anwendungsschlüssel bei einem Umzug; beides bleibt außerhalb von Git.
Beschädigte Verschlüsselung führt zu einer Fehlermeldung statt zum stillen
Reaktivieren eines alten Umgebungsschlüssels. Neue Vorgänge lesen die
gespeicherten Änderungen ohne Containerneustart.

## Odysee-Einrichtung

Öffne im Adminbereich **Einstellungen → Speicher & CDN** und wähle unter
**Externe Mediendienste** den Anbieter. Die Auswahl und Eingabefelder verwenden
dieselben Komponenten wie die übrigen Speicheranbieter. Unter
**Einrichtungsanleitung** findest du die passenden Kontoseiten:

- **Directupload:** [Uploadformular](https://www.directupload.eu/yourload.php).
  Dafür ist kein API-Schlüssel erforderlich.
- **Fastpic:** [API & ShareX](https://fastpic.org/keys) und
  [Konto](https://fastpic.org/account). Melde dich an, bestätige deine E-Mail
  und erstelle einen eigenen Schlüssel. Kopiere ihn bei der Erstellung;
  danach wird er nicht erneut angezeigt. Der angebundene Dienst ist fastpic.org.
- **Postimages:** [API-Zugang](https://postimages.org/login/api) und
  [Registrierung](https://postimages.org/signup). Übernimm deinen eigenen
  API-Schlüssel. Die Felder O und M enthalten öffentliche Kompatibilitätswerte.
- **Oracle:** [Kunden-Secret-Keys](https://cloud.oracle.com/identity/domains/my-profile/auth-tokens)
  unter „Token und Schlüssel → Kunden-Secret Keys“ sowie
  [Buckets](https://cloud.oracle.com/object-storage/buckets).
  Wähle deine Heimatregion und dein Compartment, öffne den privaten
  Standard-Bucket und übernimm Region, Namespace und Bucketnamen.
  Der Zugriffsschlüssel und der einmalig angezeigte Secret Key müssen aus
  demselben Kunden-Schlüsselpaar stammen. PEM-Signaturschlüssel und
  Authentifizierungstoken gehören nicht in diese Felder. Die Oberfläche ergänzt
  bei gültiger Region den Regionsparameter der Konsolenlinks.
- **Odysee:** [Kanäle](https://odysee.com/$/channels) und
  [Registrierung](https://odysee.com/$/signup). Melde dich anschließend direkt im
  Adminbereich an, wähle deinen Kanal und speichere die Verbindung. Dafür musst
  du keinen API-Schlüssel aus Browserwerkzeugen kopieren.

Die Links öffnen ein neues Fenster beziehungsweise einen neuen Tab. Deine
ungespeicherten Eingaben bleiben beim Anbieterwechsel und Größenwechsel erhalten.
Die Einrichtungswege sind in den drei deutschen sowie den sechs englischen
Referenzpaketen gepflegt. Die vollständige Abnahme aller
Sprachpakete ist eine getrennte Arbeit.

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

Verbindungstests zeigen Erfolg, Fehler und Einschränkungen im gemeinsamen
Admin-Ergebnisdialog, wie die übrigen Speicheranbieter. Fastpics begrenzte
Originalbildfreigabe erscheint als Hinweis. Die Dialoge lassen sich mit „Okay“
oder Escape schließen und geben den Fokus an den Testbutton zurück. Ein
erfolgreicher Bildtest stellt den Link zum Testbild nach dem Schließen bereit.

- Oracle: genau ein authentifizierter Bucket-HEAD-Aufruf. Das bestätigt Zugang,
  nicht Browser-CORS oder den vollständigen Clip-Ablauf.
- Odysee: authentifizierte Kanalliste und Zugehörigkeit des gewählten Kanals;
  keine Veröffentlichung oder Videodaten.
- Imagehoster: ausdrücklich angeforderter Upload eines synthetischen
  Ein-Pixel-Testbilds. Es werden keine Nutzerbilder verwendet. Diese Aktion
  verbraucht einen regulären Uploadversuch im lokalen Anbieterbudget.

Fastpic wird über den fest vorgegebenen, offiziell veröffentlichten
ShareX-Endpunkt `https://new.fastpic.org/upload` angesprochen, einschließlich
der Multipart-Felder `uploading`, `check_thumb`, `thumb_size` und `file1`.
Fastpics Kennzeichen `hotlinked` bezeichnet den eingeschränkten Vorschaumodus:
`false` gibt `big_link` als Originalbild frei, `true` erlaubt nur `thumb_link`.
Die frühere gegenteilige Auswertung war ein Parserfehler. Beim korrigierten
Praxistest am 7. November 2026 ließ sich die zurückgegebene Original-PNG-URL
ohne Anmeldung als echtes Bild abrufen und dekodieren. Nur tatsächlich
eingeschränkte Antworten erzeugen noch den Vorschauhinweis. Bestehende
Vorschaubild-Fallbacks bleiben erhalten; API-Fehler werden nicht als erfolgreiche
eingeschränkte Uploads gewertet.
Das synthetische Test-PNG wird zusätzlich auf tatsächlich dekodierbare Bilddaten
geprüft.

## Implementierungsstand

Die verschlüsselte Speicherung, gemeinsamen Laufzeit-Einstellungen,
authentifizierten Admin-Endpunkte und die Oberfläche mit eingebetteten
Anbieter-Anleitungen sind implementiert. Die Browserprüfung verwendet isolierte
API-Antworten und ändert keine echten Kontodaten. Sie bestätigt keine tatsächliche
Veröffentlichung oder Bildhoster-Verbindung. Schritt 2 bleibt offen, bis auch Warteschlange, Browser-Upload,
automatische Odysee-Übernahme und Bereinigung tatsächlich abgenommen sind.
