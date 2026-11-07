# Oracle-Zugang für den Clip-Zwischenspeicher

Dieser geprüfte Teil von Schritt 2 konfiguriert nur die Oracle-Verbindung.
Warteschlange, sichere Browser-Uploads, Odysee-Import und Bereinigung sind noch
nicht vollständig umgesetzt oder extern abgenommen. Es werden keine lokalen
Video-Uploads aktiviert. Der allgemeine Dateispeicher bleibt unverändert.

Trage die Werte lokal in die `.env` des laufenden ursprünglichen Projekts ein,
nicht in den Impeccable-Wartungsworktree und nicht in den Chat:

```dotenv
CLIP_STAGING_OCI_REGION=eu-frankfurt-1
CLIP_STAGING_OCI_NAMESPACE=
CLIP_STAGING_OCI_BUCKET=
CLIP_STAGING_OCI_ACCESS_KEY_ID=
CLIP_STAGING_OCI_SECRET_ACCESS_KEY=
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
