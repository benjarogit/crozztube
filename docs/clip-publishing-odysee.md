# Odysee-Zugang für die automatische Clip-Übernahme

Oracle und Odysee haben getrennte Zugänge. Die Oracle-Kunden-Secret-Keys gehören
nicht in die Odysee-Felder. Verwende ein eigenes zentrales Odysee-Konto mit
bestätigter E-Mail und vorhandenem Kanal. Der Zugang bleibt ausschließlich auf
dem Server; Clip-Nutzer erhalten ihn zu keinem Zeitpunkt.

## Was du lokal einträgst

Am einfachsten richtest du den Zugang im lokalen Terminal ein. Du brauchst keinen
Token selbst zu suchen und keinen neuen API-Key auf Odysee anzulegen:

```sh
php artisan crozztube:clips:publisher-setup
```

Starte den Befehl im ursprünglichen Projekt. Gib deine bestehende Odysee-E-Mail
und dein Passwort **nur in diesem Terminal** ein. Die Passworteingabe bleibt
verdeckt; wenn das Terminal keine verdeckte Eingabe unterstützt, wird sie nicht
ersatzweise sichtbar angezeigt. Keine Zugangsdaten als Befehlsargumente oder in
den Chat kopieren.

Der Befehl erstellt wie Odysees Webclient eine neue Clientsitzung, meldet diese
beim bestehenden Konto an, prüft bestätigte E-Mail und erreichbare eigene Kanäle
und lässt dich den Zielkanal auswählen. Er legt kein neues E-Mail-Konto an,
erstellt keinen Kanal und veröffentlicht nichts. Nach deiner Bestätigung werden
nur Kontotoken und Kanal-ID in die verschlüsselte serverseitige
Einstellungsverwaltung geschrieben, mit Dateimodus 0600. Passwort und E-Mail
werden nicht gespeichert. Bestehende `.env`-Werte bleiben als Ausgangskonfiguration
erhalten; Admin-Änderungen werden darübergelegt. Siehe
[Externe Mediendienste](admin-media-providers.md).

Zusätzliche Bestätigungsschritte oder CAPTCHAs werden nicht umgangen. Verlangt
Odysee sie, endet die Einrichtung ohne gespeicherte Zugangsdaten. Die Anmeldung
über den internen, vom offiziellen Webclient genutzten API-Weg ist erst nach
einem echten Kontotest bestätigt; die automatisierten Tests verwenden simulierte
Antworten.

Der erste Einrichtungsstand erzeugte eine Anwendungs-ID mit Bindestrichen, die
Odysee mit HTTP 400 als ungültig zurückwies. Der Fehler trat bereits vor der
Passwortprüfung auf. Der korrigierte Stand nutzt eine eigene alphanumerische
Installationskennung mit 66 Zeichen, entsprechend der Begrenzung im Webclient.
Der echte anonyme Sitzungsaufruf wurde am 7. November 2026 erfolgreich geprüft.
Das bestätigt noch keinen Kontozugriff oder Clip-Import.

Du kannst nur diesen ersten Aufruf ohne Passwort und ohne lokale Änderungen
prüfen. Dabei wird ausschließlich eine anonyme Clientsitzung beim Anbieter
erzeugt; ihr Token wird weder ausgegeben noch gespeichert:

```sh
php artisan crozztube:clips:publisher-setup --check-session
```

Fehler unterscheiden jetzt Clientsitzung, eigentliche Anmeldung und
Kontobestätigung. Es werden nur fest vorgegebene Hinweise und gegebenenfalls der
HTTP-Status ausgegeben, keine Antworttexte, Zugangsdaten oder Provider-Header.

### Felder und manuelle Alternative

In die geschützte, von Git ignorierte `.env` des ursprünglichen Projekts
`/mnt/intern-1tb/Projekte - Vibe Coding/crozztube/.env` gehören:

```dotenv
CLIP_PUBLISH_ODYSEE_AUTH_TOKEN=''
CLIP_PUBLISH_ODYSEE_CHANNEL_ID=
```

`AUTH_TOKEN` ist der bestehende **Odysee-Kontotoken**, den Odysees eigener
Webclient als `X-Lbry-Auth-Token` an seinen API-Proxy sendet. Das ist kein
Oracle-Schlüssel, kein Kanalname, kein Passwort und kein neu erfundener
API-Key aus einer vermeintlichen Odysee-Einstellungsseite. Ein kurzlebiger
OAuth-Bearer-Token ist ebenfalls nicht für dieses Feld vorgesehen.

So findest du den verwendeten Kontotoken, falls dein angemeldeter Webclient
noch diesen von der offiziellen API unterstützten Zugang verwendet:

1. Öffne Odysee im Browser und melde dich im vorgesehenen zentralen Konto an.
2. Öffne die Entwicklerwerkzeuge mit F12 und dort **Netzwerk/Network**.
3. Öffne in Odysee die Verwaltung deiner Kanäle. Filtere die Anfragen nach
   `channel_list`. Wähle eine Anfrage an `api.na-backend.odysee.com` aus.
4. Unter **Anfrage-Header/Request Headers** findest du `X-Lbry-Auth-Token`.
   Kopiere ausschließlich dessen Wert lokal zwischen die einfachen
   Anführungszeichen bei `CLIP_PUBLISH_ODYSEE_AUTH_TOKEN` und speichere die Datei.
   Kopiere keine vollständige Anfrage, Cookies, HAR-Datei oder Konsole in den Chat.
5. Gibt es diesen Header in deinem Webclient nicht, setze keinen anderen Token
   auf Verdacht ein. Dann muss der tatsächlich verwendete Anmeldeweg erst
   separat geprüft werden. Die Integration bleibt bis dahin offen.

Der Token gewährt Kontozugriff. Behandle ihn wie ein Passwort. Bei Widerruf oder
Anmeldefehlern muss die Übernahme pausieren; sie darf nicht auf ein anderes Konto
oder eine öffentliche Uploadberechtigung ausweichen. Dieser Prüfstand enthält
keine automatische Erneuerung von Kontotokens.

## Kanal ermitteln und Konto prüfen

Ein Kanal wird mit seiner vollständigen **40-stelligen hexadezimalen Claim-ID**
angesprochen. Ein `@Kanalname` oder eine abgekürzte ID aus der URL reicht nicht.
Nach dem Eintragen des Tokens kann die vorhandene Kanalliste gelesen werden:

```sh
php artisan config:clear
php artisan crozztube:clips:publisher-check --connect --list-channels
```

Führe das im ursprünglichen Projekt mit seiner PHP-Umgebung aus oder, wenn der
Webcontainer läuft, mit `docker compose -f docker/compose.yml exec -T web` davor.
Die Ausgabe enthält ausschließlich öffentliche Kanalnamen und IDs. Trage die ID
des vorgesehenen Kanals in `CLIP_PUBLISH_ODYSEE_CHANNEL_ID` ein. Anschließend:

```sh
php artisan crozztube:clips:publisher-check --connect
```

Dieser Check prüft authentifizierten Kontozugriff und ob der Zielkanal in dessen
Kanalverwaltung vorhanden ist. Er lädt keine Datei hoch und veröffentlicht
nichts. Fehlermeldungen geben weder Header noch Anbieter-Antworten aus.

## Stand und Grenzen

Die Konfiguration und die Konto-/Kanalprüfung sind ein Teil von Schritt 2.
URL-Übernahme, Verarbeitung, Wiedergabebereitschaft, Sichtbarkeitswechsel,
unveröffentlichte Sammlung und automatische Bereinigung sind erst nach ihren
tatsächlichen Integrationstests bestätigt. Die Clipfunktion wird durch diese
Felder nicht freigeschaltet. Ein Browser-Sitzungstoken ist kein Nachweis einer
dauerhaft verfügbaren oder offiziell zugesagten Drittanbieter-Schnittstelle.

### Begrenzte URL-Registrierung und Browser-Test

`OdyseeUrlImporter` registriert ausschließlich eine von `OracleStagingStore`
geprüfte Übergabekopie. Die 30 Minuten gültige Lese-URL wird serverseitig erzeugt;
beliebige Nutzer-URLs werden nicht angenommen. Der Kontotoken wird nur an den
festen API-Host geschickt. Beim Uploaddienst wird stattdessen der von Odysee
ausgestellte Uploadtoken verwendet. Beide bekannten offiziellen Uploadadressen
sind ausdrücklich freigegeben; unbekannte Ziele und Weiterleitungen werden
abgewiesen. Antwortgröße und Laufzeit sind begrenzt. Diagnoseausgaben enthalten
keine Tokens, signierten URLs oder internen Uploadreferenzen.

Die Registrierung ist eine schreibende Anfrage. Bei verlorenen oder defekten
Antworten ist das Ergebnis möglicherweise unklar. Solche Aufträge dürfen nicht
blind erneut registriert werden. Die nachfolgende Warteschlange muss den Zustand
und die interne Uploadreferenz speichern, bevor sie eine Veröffentlichung startet.

Am 7. November 2026 wurde zusätzlich ein echter Browser-Test ausgeführt:
Chromium erzeugte im Browser eine fünfsekündige synthetische H.264-MP4 mit
12.051 Bytes. Der direkte Formular-Upload zu Oracle lieferte HTTP 204 bei
regulärer Zertifikats- und CORS-Prüfung. Nach Metadatenprüfung und interner
Übergabekopie nahm Odysees URL-Dienst die signierte Adresse an. Beide Oracle-
Testobjekte wurden anschließend gelöscht und ihre Abwesenheit bestätigt.
Die PHP-Prozesse erhielten ausschließlich Metadaten, keine Videodaten.

Das bestätigt die **URL-Annahme**, noch nicht den vollständigen Abruf durch
Odysee, Veröffentlichung, Wiedergabe, Sichtbarkeit oder Produktionsbereinigung.
Es wurde bei diesem Test keine Veröffentlichung ausgelöst. Schritt 2 bleibt offen.

Der Test läuft nur ausdrücklich und mit einem Browserworker:

```sh
node tools/test/ui/clip-import-live-probe.mjs --run
```

Er nutzt die gespeicherten Zugänge des laufenden Webcontainers. Ohne `--run`
werden keine externen Anfragen gestellt. Der Test berührt weder Nutzermedien
noch bestehende Browserentwürfe. Er ist kein Warteschlangen- oder Dauertest.

Geprüfte Primärquellen:

- [Anmeldung des offiziellen Webclients](https://github.com/OdyseeTeam/odysee-frontend/blob/master/ui/redux/actions/user.ts)
- [Clientsitzung und API-Antworten des Webclients](https://github.com/OdyseeTeam/odysee-frontend/blob/master/extras/lbryinc/lbryio.ts)
- [Offizieller Authentifizierungs-Fallback](https://github.com/OdyseeTeam/odysee-api/blob/master/app/auth/middleware.go)
- [Verwendeter Token-Header](https://github.com/OdyseeTeam/odysee-api/blob/master/app/wallet/wallet.go)
- [Automatischer URL-Import](https://github.com/OdyseeTeam/odysee-api/blob/master/docs/design/publish/publish_v4_urls_client.mmd)
