# Gemeinsame ChatDev-Organisation für CrozzTube

## Umfang

Die Integration ergänzt die vorhandene Harness um vierzehn logisch getrennte
Fachrollen, eine gemeinsame Kernanweisung und ein lokales Aufgabenjournal. Sie
ist keine zweite Featureplanung und keine Installation eines externen
ChatDev-/Gedächtnisdienstes. Produktcode, Provider, Datenbanken, Medien und
Fortschrittsdaten gehören nicht zu diesem Einrichtungsauftrag.

Gemeinsame Quellen:

- `harness/chatdev/CORE.md`: verbindlicher Arbeitszyklus und Schutzgrenzen.
- `harness/chatdev/roles.json`: Verantwortung, Eingaben, Ergebnisse,
  Qualitätskriterien und Übergabe jeder Rolle.
- `harness/chatdev/facts.md`: belegte, wiederverwendbare Projektfakten.
- `harness/chatdev/decisions.md`: begründete Entscheidungen und Grenzen.
- `tools/chatdev/README.md`: englische Bedienungs- und technische Dokumentation.
- `.cursor/rules/chatdev.mdc`: kurzer Verweis auf denselben Kern für Cursor.

Die Rollen umfassen Orchestrierung, Entwicklung, Analyse, QA, unabhängige
Qualitätskontrolle, Architektur, Sicherheit, Performance, UX, Assets,
Dokumentation, Lokalisierung, Repository/CI und Infrastruktur. Nur die jeweils
benötigten Rollen arbeiten an einem Auftrag. Echte Unteragenten und sequenzielle
Prüfdurchgänge werden in Nachweisen ausdrücklich unterschieden.

## Codex-Anbindung

Der vorhandenen `AGENTS.md` kann nach Prüfung dieser kurze Abschnitt ergänzt
werden; die bestehenden Regeln bleiben vollständig erhalten:

```markdown
## Shared engineering organization

Read `harness/chatdev/CORE.md` and use the shared role definitions and local
journal through `tools/chatdev/cli.mjs`. This adds no gate or permission and
never supersedes the authoritative harness plan/progress or existing hooks.
```

Dies ist der gemeinsame Einstieg für neue Sitzungen. Es werden keine
unbestätigten Codex-Konfigurationsfelder oder erfundenen Agenten-APIs angelegt.
Cursor verwendet denselben Kern über seine kurze Projektregel. Ob eine konkrete
Laufzeit den Adapter automatisch lädt, muss dort tatsächlich beobachtet werden.

## Nutzung und Gedächtnis

Nach dem Lesen der Projektregeln:

```sh
node tools/harness/cli.mjs status
node tools/chatdev/cli.mjs setup
node tools/chatdev/cli.mjs context
node tools/chatdev/cli.mjs status
node tools/chatdev/cli.mjs validate
```

`context` liest den tatsächlichen Git-/Harness-Stand neu. Das Journal liegt
worktree-lokal unter dem von Git ermittelten privaten Pfad
`crozztube-chatdev/state.json`. Es enthält Aufträge, Risiken, Prüfungen,
Übergaben, offene Freigaben und nächste Aktionen. Es enthält keine Schlüssel,
Nutzerinformationen oder Kopie der verbindlichen Schritt-Abnahmen.

Codex und Cursor nutzen beim Öffnen desselben Checkouts dasselbe Journal. Andere
Worktrees haben eigene Arbeitsjournale; gemeinsam versionierte, bereinigte
Fakten und Entscheidungen bleiben die dauerhafte Wissensbasis. Quellen und
Prüfdatum gehören zu jeder dauerhaften Aussage. Historische Angaben in
`HANDOFF.md` dürfen die aktuellen Harness-Dateien nicht überschreiben.

Schreibzugriffe laufen über Sperre, atomaren Dateiaustausch und eine erwartete
Revision. Bei Konflikt neu lesen und fachlich zusammenführen. Bei Abbruch den
Writer-Zustand prüfen und die Wiederaufnahme dokumentieren; Sperren niemals
blind entfernen. Das englische README enthält vollständige Aufgaben- und
Reviewformate. Die CLI protokolliert gemeldete Prüfergebnisse, führt jedoch
keine beliebigen Testbefehle aus.

## Prüfnachweise und offene Abnahme

Die neue Werkzeugsuite und die bestehende Harness-Suite werden getrennt von
Produktprüfungen in temporären Git-Fixtures ausgeführt. Baseline der vorhandenen
Harness bei Bestandsaufnahme: 15 Tests bestanden. Die endgültigen Ergebnisse
für den integrierten Snapshot müssen im Abschlussbericht nach tatsächlichem
Lauf ergänzt werden; diese Dokumentation behauptet sie nicht vorab.

Beobachtet: Codex meldet `codex-cli 0.166.0`. Der Cursor-Launcher lieferte nur
GUI-/Wayland-Warnungen, keine belastbare Versionsangabe. Ein echter Wechsel mit
vollständigem Sitzungsneustart Cursor → Codex → Cursor ist damit **nicht
verifiziert**. Gemeinsame Zustandsformate und neu gestartete CLI-Prozesse prüfen
Dateikompatibilität und Persistenz, ersetzen diesen Laufzeittest aber nicht.
Auch geladene, wirksame Runtime-Hooks dürfen nicht allein aus vorhandenen
Konfigurationsdateien oder simulierten Nachrichten behauptet werden.

## Veröffentlichung und nächste zulässige Aktion

Der Nutzer hat einen eigenständigen, eng begrenzten ChatDev-Werkzeugpfad
inzwischen ausdrücklich freigegeben. Er ergänzt die zwei Befehle
`chatdev-verify` und `chatdev-checkpoint --message "..."` in der Harness.
Er gilt ausschließlich für `codex/chatdev-integration` auf Grundlage des
veröffentlichten `origin/main`. Plan, Fortschritt, bestehende Hooks,
Feature-Workflow und Impeccable-Gate bleiben unverändert.

Der Pfad prüft Rollen-/Werkzeugtests, die bestehenden Harness-Regressionen und
den exakt gestagten Stand auf Geheimnisse. Er bindet Nachweise an Quelle, Index,
Branch und Basis und bestätigt den Push am Remote. Unpassende Dateien,
Symlinks, Löschungen, veraltete Prüfnachweise und eine inzwischen geänderte
veröffentlichte Basis sperren die Veröffentlichung. Freigabe dieses
Werkzeugpfads bedeutet weder Feature-Abnahme noch Hook-Vertrauen oder Merge in
main. Die tatsächlich veröffentlichten Commits und der PR gehören in den
Abschlussbericht, nicht als vorweggenommene Erfolgsmeldung hierher.

Vor dem nächsten Arbeitsschritt den Status neu lesen. Für laufende Aufgaben
liefern `show AUFGABEN-ID` und `route DATEIPFAD ...` lesbare Übergaben und
Rollenempfehlungen; sie starten keine Unteragenten. Verwaiste Journalsperren
bleiben geschlossen. Es gibt keinen automatischen Entsperrbefehl: vor manueller
Bereinigung alle Writer anhalten und die Eigentümerschaft von
`state.json.lock` prüfen.

Keine kostenpflichtige Integration und kein wiederkehrender Workflow werden
stillschweigend aktiviert. Vorhandene Impeccable-Pflege bleibt getrennt.
Nächste zulässige Arbeit: den integrierten Snapshot unabhängig prüfen, über den
freigegebenen Werkzeugpfad sichern und einen reviewfähigen PR vorbereiten. Der
aktive Feature-Schritt bleibt offen; weitere Produktfunktionen warten.
