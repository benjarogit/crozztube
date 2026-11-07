# Odysee-Zugang für die automatische Clip-Übernahme

Oracle und Odysee haben getrennte Zugänge. Die Oracle-Kunden-Secret-Keys gehören
nicht in die Odysee-Felder. Verwende ein eigenes zentrales Odysee-Konto mit
bestätigter E-Mail und vorhandenem Kanal. Der Zugang bleibt ausschließlich auf
dem Server; Clip-Nutzer erhalten ihn zu keinem Zeitpunkt.

## Was du lokal einträgst

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

Geprüfte Primärquellen:

- [Offizieller Authentifizierungs-Fallback](https://github.com/OdyseeTeam/odysee-api/blob/master/app/auth/middleware.go)
- [Verwendeter Token-Header](https://github.com/OdyseeTeam/odysee-api/blob/master/app/wallet/wallet.go)
- [Automatischer URL-Import](https://github.com/OdyseeTeam/odysee-api/blob/master/docs/design/publish/publish_v4_urls_client.mmd)
