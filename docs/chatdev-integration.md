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

## Projektmanager und Fortsetzung

Der Projektmanager ergänzt die ursprünglichen 14 Rollen als 15. Rolle.
Vor der Aufgabenverteilung läuft node tools/chatdev/cli.mjs project-manager;
pr-audit reicht sein frisches Ergebnis direkt an die Koordination weiter.
Der Bericht liest Meilensteine aus der führenden Harness und ordnet bestehende
Aufgaben, Abhängigkeiten, Freigaben und Blocker. Der Orchestrator wertet ihn aus
und übernimmt die nächste zulässige Aktion in das gemeinsame Arbeitsjournal.

Der echte Projektmanager-Unteragent hat die CLI ausgeführt und seine Befunde
an den Orchestrator zurückgegeben. Die alte beschreibende Abhängigkeit wurde
mit der validierten Journalschnittstelle berichtigt; Schritt 8 ist als konkrete
Fortsetzungsaufgabe vorhanden. Nichts setzt abgenommene Schritte zurück.
Siehe harness/chatdev/step8-handoff.md für die fachlichen Anschlussstellen und
die tatsächliche Veröffentlichungsgrenze des main-gebundenen Feature-Gates.

Mehrdeutige gespeicherte PR-Berichte bleiben gesperrt; ein frischer Aufruf
pr-audit PR_NUMMER BELEGDATEI übergibt den eindeutig gemeinten PR. Weder dieser
Bericht noch eine Rollenbewertung erteilt Merge-, Deployment- oder Hook-Rechte.

## Nativer Skill-Einstieg

Eine Projektregel und die CLI allein erzeugen keinen aufrufbaren Skill.
Die gemeinsame Vorlage liegt nun in `tools/chatdev/skill/SKILL.md`.
`node tools/chatdev/cli.mjs install-skill` installiert sie ausdrücklich unter
`~/.agents/skills/chatdev/`; dieser lokale Benutzerpfad wird von Codex und
Cursor unterstützt. Codex-Aufruf: `$chatdev`. Cursor-Aufruf: `/chatdev` oder
per `@` anhängen. Bei veraltetem Picker eine neue Agent-Sitzung öffnen.

Es gibt weiterhin nur die vorhandene ChatDev-Implementierung. Der Skill lädt
den Kern aus dem aktuellen Checkout und prüft zuerst dessen Verfügbarkeit.
Insbesondere enthält der ursprüngliche main-Checkout die noch nicht gemergte
Implementierung nicht automatisch. Andere Rechner benötigen eine eigene
Installation; ein lokaler Benutzer-Skill wird nicht automatisch auf Cloud- oder
Remote-Worker übertragen. Vorhandene fremde Skills und lokale Anpassungen
werden nicht überschrieben. Wiederholte Installation ist ohne Änderungen ein
No-op; Hinweise zur sicheren Wiederherstellung stehen in der CLI-README.

Nachgewiesen sind Codex-Katalogerkennung und ein echter schreibgeschützter
Skill-Aufruf. In dessen Sandbox scheiterte allerdings `harness status` mit
`spawnSync git EPERM`; Kontext und Projektmanager liefen. Der vollständige
Cursor-Lauf sowie Cursor→Codex→Cursor bleiben offen. Die genaue Abgrenzung
steht in `harness/chatdev/skill-verification.md`. Bestehende Gates und der
aktuelle Featurefortschritt werden dadurch nicht geändert.
