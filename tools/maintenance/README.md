# Impeccable-Pflege

Die wöchentliche Codex-Aufgabe läuft montags um 09:00 Uhr, Europe/Berlin.
Rechner und Desktop-App müssen laufen. Ein manueller Lauf verwendet dieselben
Befehle. Die Aufgabe ist Werkzeugpflege, kein Abschluss des offenen Clip-Schritts.

## Quellen und Aktualisierung

`harness/impeccable.lock.json` erfasst Skill, Engine und die CLI-Referenz getrennt,
einschließlich SHA-256 aller 66 ursprünglich eingebundenen Dateien und ihrer
Upstream-Git-Objekte. Die npm-CLI ist hier nicht installiert; ihr Release wird
als Referenz verfolgt, nicht als angeblich installierte Abhängigkeit ausgegeben.
Lizenzen, Hinweise, ursprüngliche Herkunft und die bestehende Windows-Anpassung
bleiben erhalten. Nicht dokumentierte lokale Änderungen stoppen das Update.

```sh
node tools/maintenance/impeccable.mjs check
node tools/maintenance/impeccable.mjs apply
```

`check` verändert keine Dateien. `apply` übernimmt nur konsistente stabile
Releases aus `pbakaus/impeccable`, mit festen Quell-Commits und Git-Objektprüfung.
Lokale Anpassungen werden per Drei-Wege-Vergleich erhalten. Konflikte stoppen
vor dem Schreiben. Aus Upstream entfernte Dateien werden zur manuellen Prüfung
behalten, nicht still gelöscht. Unvollständige Engine-Releases werden als
`pending` gemeldet; fremde Release-Prüfsummen ersetzen kein Installer-Manifest.

Für Engine-Updates darf ein späterer festgehaltener Upstream-Commit den Pin,
beide Launcher und das vollständige Prüfsummenmanifest liefern. Er übernimmt
keine unveröffentlichten Skilltexte. Ein CLI-Release aktualisiert nur die Referenz.

## Isolierter PR

Nur in einem isolierten Worktree auf `codex/impeccable-maintenance` arbeiten.
Basis ist der frisch abgefragte veröffentlichte Branch aus `harness/plan.json`.
Vorhandene offene Wartungs-PRs und deren Remote-Branch wiederverwenden; niemals
force-pushen. Bei abweichendem Remote-Stand erst konfliktfrei integrieren.
Ein `git merge --no-commit --no-ff` des veröffentlichten Plan-Branches darf vor
dem Merge-Commit geprüft werden. Die Wartungsprüfung vergleicht dann mit genau
diesem eingehenden Stand und lehnt andere Merge-Quellen sowie zusätzliche
Feature-Änderungen ab. Erst `maintenance-checkpoint` erstellt den geprüften
Merge-Commit und bestätigt seinen Push.

```sh
node tools/harness/cli.mjs status
node tools/harness/cli.mjs resume
# Änderungen ansehen, ausschließlich passende Pfade explizit stagen.
node tools/harness/cli.mjs maintenance-verify
node tools/harness/cli.mjs maintenance-checkpoint --message "chore: update pinned Impeccable tooling"
```

Die Wartungsprüfung kontrolliert den gesamten Branch-Diff zur Basis,
einschließlich unstaged/untracked Dateien. Feature-Code, Fortschrittsdaten,
Feature-Checkdefinitionen und Hookdefinitionen sind ausgeschlossen. Sie bindet
den Prüfbeleg an Quelle, Index, Branch und Basis und bestätigt den Push mit einer
neuen Remote-Abfrage. Es gibt keinen automatischen Merge und keinen `begin N`.

Danach mit `gh pr list --head codex/impeccable-maintenance --state open` den
vorhandenen PR finden; Beschreibung aktualisieren oder genau einen neuen PR
gegen den Plan-Branch erstellen. Mehrzeilige Beschreibungen per `--body-file`.
Ein PR dokumentiert Versionen, Quell-Commits, erhaltene Anpassungen, Prüfungen
und ausstehende Releases. Fehler bleiben sichtbar; kein grüner Ersatztest.
Ohne Änderungen und ohne neuen Blocker kein weiterer PR und keine Meldung.

## Prüfungen

`maintenance-verify` führt Harness-/Updater-Tests, die vollständige
Index-Geheimnisprüfung und einen echten Engine-Test aus: heruntergeladene Bytes
werden vor Ausführung anhand des festgehaltenen Manifests geprüft, der Launcher
muss die richtige Engine melden und der Detektor einen bekannten CSS-Verstoß
finden. Temporäre Binärdateien und Fixtures werden anschließend gelöscht.

Gitleaks zuerst mit `node tools/harness/setup.mjs` installieren. Die Wartung
ändert weder gemeinsame Git-LFS-Hooks noch `.codex/hooks.json`. Künftige
erforderliche Hook-Änderungen benötigen eine getrennte Benutzerprüfung; sie
dürfen den Wartungspfad nicht als Trust-Umgehung verwenden.
