# Oracle-Zugang für den Clip-Zwischenspeicher

Dieser Teil von Schritt 2 enthält die Oracle-Verbindung und einen getrennten
Objekttransport mit begrenzten Uploadrechten. Warteschlange, Browser-CORS,
Odysee-Import und automatische Bereinigung sind noch nicht vollständig umgesetzt
oder extern abgenommen. Es werden keine lokalen
Video-Uploads aktiviert. Der allgemeine Dateispeicher bleibt unverändert.

Trage die Werte lokal in die `.env` des laufenden ursprünglichen Projekts ein,
nicht in den Impeccable-Wartungsworktree und nicht in den Chat:

```dotenv
CLIP_STAGING_OCI_REGION=eu-frankfurt-1
CLIP_STAGING_OCI_NAMESPACE=
CLIP_STAGING_OCI_BUCKET=
CLIP_STAGING_OCI_ACCESS_KEY_ID=
CLIP_STAGING_OCI_SECRET_ACCESS_KEY=
CLIP_STAGING_ACTIVE_GLOBAL=3
CLIP_STAGING_NEW_PER_USER_DAY=5
CLIP_STAGING_RESERVED_BYTES_PER_ACCOUNT=1000000000
```

Die letzten beiden Werte stammen aus **Mein Profil → Token und Schlüssel →
Kunden-Secret Keys**. Der Secret Key wird nur einmal angezeigt. Speichere beide
Werte geschützt und umschließe sie beim Eintragen mit einfachen Anführungszeichen.
Der Zugang braucht Bucket-/Objektberechtigungen; ein eigener technischer Benutzer
mit Zugriff nur auf den Clip-Bucket ist für den Betrieb vorgesehen.

Region und Namespace findest du in der Oracle-Konsole bei Object Storage bzw.
den Mandantendetails. Erstelle einen privaten Standard-Bucket in deiner
Heimatregion. Der Bucketname muss exakt dem Eintrag in der Konsole entsprechen.
Es wird kein frei konfigurierbarer Endpoint akzeptiert: Der S3-Endpunkt wird aus
Namespace und Region unter Oracles fester HTTPS-Domain abgeleitet.

Die Verbindung ist zunächst für den Bucket in deiner OCI-Heimatregion ausgelegt.
Der S3-Client signiert mit dem von Oracle dokumentierten Kompatibilitätswert
`us-east-1`. Das ist der Signaturbereich, kein Speicherort: Der HTTPS-Endpunkt
bleibt etwa in `eu-frankfurt-1`. Im tatsächlichen Frankfurt-Test wurde die
Signatur mit `eu-frankfurt-1` abgelehnt, während `us-east-1` mit demselben
Schlüsselpaar erfolgreich war. Eine Nutzung außerhalb der Heimatregion benötigt
eine eigene Prüfung; der Verbindungscheck bestätigt nicht automatisch weitere
Regionen.

Nach dem Eintragen kann die Konfiguration ohne Netzwerkanfrage geprüft werden:

```sh
docker compose -f docker/compose.yml exec -T web php artisan config:clear
docker compose -f docker/compose.yml exec -T web php artisan crozztube:clips:storage-check
```

Erst mit `--connect` erfolgt genau ein lesender Bucket-HEAD-Aufruf. Er verändert
keine Dateien und ist nur ein Zugangscheck, kein Beweis für private Speicherung,
Browser-CORS, Upload, Hoster-Übernahme oder Löschung. Ein neuer Kunden-Secret-Key
allein bestätigt auch keine Bucket-Berechtigungen.

Die `.env` bleibt ignoriert und erhält lokal Dateimodus 0600. Zugangsdaten werden
weder in Diagnoseausgaben noch in Frontend-Buildvariablen ausgegeben. HTTP-Fehler
werden ohne SDK-Details dargestellt, da diese signierte URLs enthalten können.

Bleib im Free-Tier-Konto ohne Pay-As-You-Go-Upgrade. Testguthaben ist keine
dauerhafte Kapazität; beim Ablauf dürfen insbesondere nicht mehr als 20 GB Object
Storage belegt sein. Wir behalten zunächst eine deutlich kleinere Belegungsgrenze.

Quellen: [S3-Kompatibilität und Zugang](https://docs.oracle.com/en-us/iaas/Content/Object/Tasks/s3compatibleapi.htm),
[Always-Free-Grenzen](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm).

## Begrenzte Uploadrechte und Übergabekopie

`OracleStagingStore` stellt eine höchstens zehn Minuten gültige POST-Berechtigung
für genau einen serverseitig zugewiesenen UUID-Objektnamen aus. Die signierte
Policy bindet Bucket, MP4-Inhaltstyp, exakte Bytezahl und SHA-256-Prüfsumme.
Die Dateigröße ist auf 64 MiB begrenzt. Kontoschlüssel bleiben serverseitig;
das Formular enthält die abgeleitete Signatur und die öffentliche Zugriffsschlüssel-ID.

Nach dem Upload prüft der Server ausschließlich Objektmetadaten. Oracle kopiert
das Objekt intern vom Präfix `clips/incoming/` nach `clips/handoff/`. Eine
ETag-Bedingung verhindert, dass eine zwischenzeitlich ersetzte Quelldatei
unbemerkt kopiert wird. Auch die fertige Kopie wird geprüft. Der Hoster erhält
eine eigene, höchstens eine Stunde gültige Leseberechtigung für diese Kopie.
Die Anwendung liest oder transportiert bei diesen Operationen keine Videodaten.

Das Browser-Formular berechtigt nicht zum Schreiben auf das Handoff-Präfix.
Es kann aber bis zum Ablauf für dieselbe Incoming-Datei erneut verwendet werden.
Deshalb muss die spätere Warteschlange beide Kopien reservieren, abgelaufene
Aufträge nach laufenden Uploads erneut bereinigen und die Reservierung bis zur
bestätigten abschließenden Bereinigung behalten. `deleteCopies` allein ist kein
Nachweis einer dauerhaft abgeschlossenen Auftragsbereinigung.

Der externe Protokolltest wurde am 7. November 2026 mit 256 synthetischen
Textbytes durchgeführt: manipulierte Formularfelder, falsche Bytezahl und
falscher Dateiinhalt wurden abgewiesen; korrekter POST, abgelehnte Kopie mit
falschem ETag, validierte interne Kopie, verweigerter anonymer Abruf,
signierter Abruf und bestätigte Löschung beider Objekte waren erfolgreich.
Das war kein Video- oder Browser-Test und kein Odysee-Import.

Die drei Betriebsgrenzen entsprechen dem genehmigten Ausgangsstand. Der
Workerlauf liest sie aus der zentralen Konfiguration und synchronisiert die
aktuelle Admin-Verbindung vor neuen Aufnahmen. Ungültige Werte oder eine
unvollständige Verbindung stoppen neue Aufnahmen, nicht die Bereinigung bereits
gebundener Aufträge.

Der ausdrücklich gestartete Test erzeugt ausschließlich einen zufällig benannten
Protokoll-Testauftrag und löscht nur dessen eigene beiden Objekte:

```sh
php tools/test/php/clip-staging-live-probe.php --run
```

Ohne `--run` erfolgt keine Anfrage. Der Offline-Harness führt diesen externen
Test nicht automatisch aus. Für einen Live-Test des späteren Gesamtablaufs
sind weiterhin Browser, Warteschlange und Odysee-Verarbeitung nötig.

Quelle: [Oracles POST-Policy, Größen- und Prüfsummenbedingungen](https://docs.oracle.com/en-us/iaas/Content/Object/s3-post-upload.htm).
