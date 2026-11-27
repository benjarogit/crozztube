# CrozzTube – GitHub-Qualität und Sicherheit

Stand: 2026-11-14. Diese Einrichtung ist ein Vorschlag, kein Produkt- oder Merge-PASS.

## Bestehende Regeln

- `AGENTS.md`, `PRODUCT.md`, `DESIGN.md`, `harness/plan.json` und `harness/progress.json` sind maßgeblich.
- Schritt 8 bleibt offen: Cursor → Codex → Cursor, lokale Integration/Migration, Live-Abnahme und sprachliche Prüfung sind nicht vollständig nachgewiesen.
- PR #3 (ChatDev) und darauf aufbauend PR #4 (Serien/Staffeln) sind eigenständige Entwicklungsarbeiten. Deren CI/Checks und Abhängigkeiten bei jeder Integration berücksichtigen.
- Keine erzwungenen Schritte, Auto-Merges, Deployments oder Veröffentlichungen. Keine Veränderung an Runtime-Hooks und Git-LFS.
- Harness- und GitHub-Checks dürfen unterschiedliche Testumfänge haben; ein grüner Workflow ist keine vollständige Live-Abnahme.

## GitHub-Änderungen dieses Vorschlags

- PR- und Issue-Vorlagen für Nachweise, Akzeptanzbedingungen und sensible Daten.
- Begrenzte monatliche Dependabot-Update-PRs für GitHub Actions, npm und Composer (maximal 2 pro Ökosystem). Updates werden **nicht** automatisch gemergt. Vor der Übernahme prüfen, ob lockfiles, PHP/Node-Versionen und vorhandene Harness-Tests passen.

## Repository-Einstellungen, die noch separat geprüft werden müssen

1. **Branches / Rulesets:** Für `main` Pull Requests verlangen, Force-Push und Löschen untersagen. Nur tatsächlich existierende und nachweislich stabile Statuschecks verpflichtend machen; gestapelte PRs #3/#4 nicht aussperren.
2. **Actions:** Token standardmäßig read-only, untrusted Fork-PRs nicht mit Secrets oder auf einem geteilten self-hosted Runner ausführen. Release-/Deployment-Berechtigungen eng begrenzen.
3. **Security:** Dependency graph, Dependabot alerts, secret scanning, push protection und private vulnerability reporting nach Verfügbarkeit prüfen und aktivieren.
4. **CodeRabbit:** Installation und Review-Kommentare kontrollieren. Es ersetzt keine unabhängige funktionale Abnahme und kann für ein privates Repository tarifpflichtig sein.
5. **CodeQL:** Das Repository ist privat. CodeQL-Code-Scanning und etwaige kostenpflichtige GitHub-Sicherheitsfunktionen erst nach bestätigter Plan-/Lizenzberechtigung aktivieren. Kein Workflow, der unerwartete Kosten oder blockierende Pflichtchecks erzeugt.
6. **Projektverwaltung:** GitHub Issues mit bestehenden Harness-Aufgaben und Nachweisen verknüpfen statt einen konkurrierenden Fortschrittsstatus aufzubauen.

## Nächste technische Arbeit

- Die existierenden Workflows auf den aktiven Entwicklungsbranches inventarisieren, bevor gemeinsame CI-Dateien geändert werden.
- Ein schlankes PR-Gate gegen Syntax, Format, statische Fehler und Secrets festlegen, das auf den vorhandenen Befehlen basiert; Browser-, Medien-, Datenbank- und Gerätekontrollen getrennt ausweisen.
- Push- und PR-Checks so einrichten, dass keine überschüssigen kostenpflichtigen Runner-Minuten entstehen. Bei privatem Repository sind Hosted-Runner-Kontingente planabhängig.
- Anstehende Branch-Merges nur in der dokumentierten Abhängigkeitsfolge und nach Freigabe planen.
