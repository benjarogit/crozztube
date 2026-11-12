# Geordnete Umsetzung

Die ausdrücklich genehmigte Impeccable-Werkzeugpflege verwendet den isolierten
Wartungspfad unter `tools/maintenance/README.md`, `maintenance-verify` und
`maintenance-checkpoint`. Sie verändert keine Feature-Fortschrittsdaten und
ersetzt keine fachliche Abnahme.

Der genehmigte Umfang und seine Anfangswerte stehen in `plan.json`, der
versionierte Fortschritt in `progress.json`. Es ist genau ein Schritt aktuell.
Der gesicherte Vorzustand und seine Prüfungen sind in `baseline.md` beschrieben.

## Abschluss bedeutet geprüft, committed und gepusht

```sh
node tools/harness/cli.mjs status
node tools/harness/cli.mjs resume
# Nur den aktuellen Schritt umsetzen; relevante Prüfungen in plan.json ergänzen.
git add -- <bewusst-geprüfte-Dateien>
node tools/harness/cli.mjs verify --checkpoint
node tools/harness/cli.mjs checkpoint --message "test: describe a tested increment"
# Nach vollständiger fachlicher Abnahme:
node tools/harness/cli.mjs verify
node tools/harness/cli.mjs complete --message "feat: describe completed step" --note "concrete acceptance evidence"
node tools/harness/cli.mjs begin 2
```

`verify` führt die vorgeschriebenen Prüfungen ohne Shell-Interpolation aus und
bindet das Ergebnis an den Inhalt **aller** versionierten und nicht ignorierten
Quelldateien, einschließlich Checkdefinitionen, Hooks und Dokumentation.
Allein die nachträgliche Fortschrittsdeklaration ist vom Digest ausgenommen.
Neue Quelländerungen machen die Prüfung ungültig. `checkpoint` und `complete`
stagen keine fremden Dateien; nur `complete` übernimmt seine eigene
Fortschrittsdeklaration ausdrücklich in den Index.

`verify --checkpoint` verwendet die ausdrücklich festgelegten
`checkpointChecks` des aktuellen Schritts, falls vorhanden. So können geprüfte
Teilkomponenten gesichert werden, während eine externe Gesamtabnahme noch
aussteht. Dieser Nachweis kann einen Schritt **nicht** abschließen; dafür sind
`verify` und sämtliche vollständigen Schrittprüfungen erforderlich.

`accepted` im versionierten Protokoll bedeutet fachlich abgenommen und zur
Publikation vorgesehen. Erst der bestätigte Remote-Stand und ein sauberer
Arbeitsbaum ergeben im Harness den Zustand `complete`. Ein fehlgeschlagener
Push bleibt eine Blockade, auch wenn der Commit schon lokal existiert.
`begin N` prüft den Remote-Stand erneut und erlaubt nur den direkten Nachfolger.
Nach einem Pushfehler: Ursache beheben, `resume`, anschließend denselben
`complete`- beziehungsweise `checkpoint`-Aufruf erneut ausführen.

Es gibt weder automatische Force-Pushes noch Änderungen an gemeinsamen
Git-LFS-Hooks. Der Zielbranch ist `origin/codex/viewer-community-features`.

## Rückfragen, Unterbrechungen und Blockaden

```sh
node tools/harness/cli.mjs mode discussion --reason "Answering a product question."
node tools/harness/cli.mjs mode paused --reason "Explicit user pause."
node tools/harness/cli.mjs mode blocked --reason "Describe the actual external blocker."
```

Ein Moduswechsel stellt einen Schritt nicht fertig und erlaubt keinen
Schrittwechsel. Der Stop-Hook erzeugt höchstens eine Reparaturfortsetzung;
bleibt der Abschluss offen, meldet er eine Blockade statt einer Endlosschleife.
Fragen und ausdrückliche Unterbrechungen respektieren die Nutzersteuerung.

## Tatsächliche Projekt-Hooks prüfen

Cursor lädt die aktive Definition aus `.cursor/hooks.json`; die parallele
`.codex/hooks.json` bleibt für den älteren Codex-Laufzeitweg erhalten. Öffne
**Customize → Hooks**, um die geladene Projektdefinition und ihre Ausführungen
zu sehen. Es genügt nicht, dass nur eine Konfigurationsdatei existiert.

Nach der Vertrauensprüfung wird der harmlose Negativtest **als Agent-Toolaufruf**
ausgeführt, nicht als manuell simulierte stdin-Nachricht:

```sh
node tools/harness/cli.mjs deny-probe
node tools/harness/cli.mjs runtime-check
```

Der erste Befehl muss vor seiner Ausführung mit
`CROZZTUBE_HARNESS_PROBE_BLOCKED` abgewiesen werden. Läuft er doch, meldet er
einen Fehler. Erst ein passender Runtime-Beleg mit Session-ID und aktuellem
Hash von aktiver Definition und Hookcode erfüllt `runtime-check` und die
Abschlussvoraussetzung. Ein Cursor-Shellereignis liefert derzeit nicht in jeder
Version eine Session-ID; das echte blockierte Ereignis selbst bleibt zwingend.

## Prüfwerkzeuge und Geheimnisse

```sh
node tools/harness/setup.mjs
node tools/harness/scan-secrets.mjs
```

Das Setup installiert die festgelegte, checksum-geprüfte Gitleaks-Version nur
unter `.git/crozztube-harness/bin/`. Alternativ `GITLEAKS_BIN` setzen.
Die vollständige Indexkopie wird in einem temporären Verzeichnis mit 0700
gescannt und danach entfernt. Fundwerte werden nie ausgegeben. Ausnahmen
sind eng begrenzt und in `.gitleaks.toml` dokumentiert.

Prüfbelege, Hookereignisse und Publikationsbestätigungen liegen nur unter
`.git/crozztube-harness/`, ohne Prompts, Toolargumente oder Zugangsdaten.
Ein neuer Checkout benötigt neue Prüf- und Runtime-Belege. Vite-Zwischenstände
bleiben lokal; neue vom Release-Manifest referenzierte Assets werden gezielt
mit `git add -f -- build/assets/<datei>` aufgenommen.

Die verbleibenden Schritte sind absichtlich noch nicht umgesetzt. Ihre
Checknamen sind Abschlussanforderungen und keine erfolgreich bestandenen
Platzhalter. Schritt 2 verwendet den behaltenen synthetischen Besitzerclip für
eine begrenzte echte Odysee-Wiedergabeprüfung; neue Veröffentlichungen gehören
nicht in den automatischen Abschlusscheck.
