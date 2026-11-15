# Stabile Tooling-Basis für gestapelte Feature-Arbeit: Vorschlag

Stand: 15. November 2026. Dieser Text erklärt den bestehenden Ablauf und schlägt eine eng begrenzte spätere Verbesserung vor. Er implementiert keine Gate-Änderung, keine neue Infrastruktur und keine zusätzliche Merge-Freigabe.

## Warum die Parent-Integrationen wiederholt nötig waren

Die Wiederholungen entstehen aus der Kopplung der Feature-Basis an die jeweils neueste veröffentlichte Spitze des noch offenen Tooling-PRs. Nach jeder eigenständigen Tooling-Korrektur verlangt das bestehende Gate wieder diese neue Spitze im Feature-Verlauf. Das sind verschiedene Tooling-Korrekturen, keine fünfmal wiederholte Serienimplementierung.

`seriesScope` in `tools/harness/series-maintenance.mjs` wählt die Basis aus `origin/codex/chatdev-integration` und verlangt, dass diese Basis im Feature-Verlauf liegt. `publishedParent` vergleicht zusätzlich bei Prüfung und Veröffentlichung die frische Remote-Spitze mit dieser Basis. Ein alter lokaler Remote-Verweis löst das deshalb nicht. Nach jedem Tooling-Push muss die neue Spitze integriert werden, und die an Basis, Source und Index gebundenen Nachweise werden veraltet. Die fest genehmigten kanonischen Merge-Anker verlangen dafür jeweils eine konkrete neue Freigabe.

Die vier tatsächlichen Integrationen betrafen unterschiedliche Korrekturen:

- `c17e930` integrierte `c8d4039`: die begrenzte Serien-Parent-/Historienprüfung.
- `3a07a5a` integrierte `be06d13`: den bereits vorhandenen kanonischen russischen Sprachpfad.
- `88e7313` integrierte `ac40645`: die Trennung optionaler Editor-Interoperabilität von Produktabnahme und den gebundenen Parent-Fortschritt.
- `27de4ee` integrierte `2a8273f`: die Reparatur konkurrierender Hook-Receipt-Schreibvorgänge und den Erhalt berechneter Ablehnungen.

Die separat genehmigte fünfte Integration betrifft die danach unabhängig reproduzierte Stage-0-Lücke: Indexmodus oder Indexblob konnten hinter einem korrekten Arbeitsbaum verborgen werden. Der veröffentlichte Hook hatte den korrekten Blob und Modus; eine betroffene Veröffentlichung wurde nicht behauptet. Die fünfte Genehmigung ist kein allgemeines Recht auf weitere Parent-Merges.

## Begrenzter Änderungsvorschlag

Die ausdrücklich ausgewählte, geprüfte und veröffentlichte Tooling-Version sollte von der beweglichen Branch-Spitze getrennt werden. Ein Routine-Push auf dem Parent darf dann als verfügbar angezeigt werden, ohne automatisch Source zu importieren oder einen Feature-Merge zu verlangen.

Die ausgewählte Basis braucht eine eng geschützte, nachvollziehbar geprüfte Zuordnung zu einem exakten veröffentlichten Commit und der genehmigten kanonischen Integration. Sie darf nicht über freie Umgebungsvariablen, CLI-Parameter oder gewöhnliche Feature-Patches ersetzbar sein. Zu binden sind Basis-Commit, Repository-/Remote-Identität, Integrations-/Policy-Version, Review- und Testnachweise sowie eine ausdrückliche Sperre für eine bekannt nicht mehr vertretbare Version. Die konkrete Ablage und der Bootstrap müssen separat entworfen und negativ getestet werden; eine Konstante mit dem Hash ihres eigenen erst zu erzeugenden Commits wäre keine tragfähige Lösung. Es wird keine zweite Featureplanung und kein neuer Dienst vorgeschlagen.

Bei normalen Feature-Checkpoints bleiben die ausgewählte Basis und die ausdrücklich genehmigte Historie fest. Die tatsächliche Veröffentlichung und Herkunft werden frisch geprüft; ein unveröffentlichter, fremder oder aus der veröffentlichten Linie entfernter Commit bleibt gesperrt. Ein neuer Nachfolger auf dem Branch allein hebt die Basis nicht automatisch an. Der bisherige historische Pfad-/Plan-Audit, Index-/Asset-/Secret-Prüfungen, geschützte Hooks und die bestehenden Checkpoint-/Abschlussregeln bleiben verbindlich. Receipts müssen die ausgewählte Basis und die konkrete Policy ebenso binden wie Source, Index, Checks und Remote.

Eine bekannt fehlerhafte oder gesperrte Sicherheitsbasis wird damit nicht konserviert: Die aktuelle P2-Korrektur muss über die bereits konkret genehmigte fünfte Integration übernommen werden. Eine betroffene alte Basis bleibt bis zur ausdrücklichen geprüften Korrektur gesperrt. Ein früher grüner Test ersetzt diese Entscheidung nicht.

Dringende spätere Basiswechsel bleiben einzeln konkret: veröffentlichten Ziel-Commit, aktuellen Feature-Anker und konfliktfreien kanonischen Baum prüfen; erforderliche Freigabe einholen; genau diese Integration unter dem bestehenden eng begrenzten Werkzeugpfad registrieren; alle alten Anker und historischen Prüfungen erhalten; alte Receipts verwerfen; relevante Prüfungen, unabhängige Reviews, CI und bestätigte Veröffentlichung erneuern. Kein blindes Auto-Importieren und keine pauschale Zulassung beliebiger Remote-Vorfahren.

## Verhältnis zu den offenen PRs

Der frisch gelesene Stand ist [PR #3](https://github.com/benjarogit/crozztube/pull/3) gegen `main`, [PR #4](https://github.com/benjarogit/crozztube/pull/4) gegen `codex/chatdev-integration` und der unabhängige Entwurf [PR #5](https://github.com/benjarogit/crozztube/pull/5) gegen `main`. #5 ist kein zusätzlicher Pflicht-Parent von #4.

Eine feste lokale Harness-Basis ändert den beweglichen GitHub-Zielbranch von PR #4 nicht automatisch. Dessen Diff, Merge-Ergebnis und CI können sich mit dem Parent weiter ändern. Reviews müssen deshalb den tatsächlichen PR-Base-SHA zusätzlich zu Feature-Head und ausgewählter Tooling-Version berücksichtigen oder bei geändertem Base-Stand erneuert werden. GitHub-Schutzregeln und Merge-Anforderungen werden durch einen Harness-Pin nicht erlassen.

Für künftige Stacks ist vorzuziehen, grundlegendes Tooling zunächst fertigzustellen. PR #3 darf erst nach Behebung der P2-Lücke, echten Review-/CI-/Schutzanforderungen und ausdrücklich autorisiertem Main-Merge landen. Danach wird die Basis beziehungsweise ein Retarget des bestehenden PR #4 gesondert geprüft und nur mit der passenden Freigabe durchgeführt. Das akzeptiert weder Schritt 8 noch startet es Schritt 9. PR #5 bleibt eine eigenständige Governance-Änderung; ältere Beschreibungstexte dort überstimmen die aktuelle Harness nicht.

## Nutzen, Kosten und notwendige Negativprüfungen

Die heutige Spitzengleichheit ist einfach und erzwingt stets das neueste veröffentlichte Tooling, koppelt aber jede Werkzeugkorrektur an alle gestapelten Features. Eine feste geprüfte Basis macht Arbeitspakete reproduzierbarer; dafür braucht sie eine präzise geschützte Versionswahl, frische Herkunftsprüfung, Sicherheits-Sperren und die getrennte Behandlung des GitHub-Base-Standes. Tooling vor neuen Feature-Stacks zu landen reduziert die Abhängigkeit, setzt aber echte Bereitschaft und ausdrückliche Merge-Freigabe voraus.

Vor einer Umsetzung sind mindestens diese Fälle zu prüfen:

- Eine fortgeschrittene Parent-Spitze importiert nichts und erzeugt keine fingierte Verifikation.
- Unveröffentlichte/fremde/umgeschriebene Basis, freie Overrides und unautorisierte Pin-Änderungen werden abgewiesen.
- Eine ausdrücklich gesperrte problematische Version bleibt trotz früher grüner Checks gesperrt.
- Ein genehmigter Basiswechsel macht alte Nachweise ungültig; falsche Eltern, zusätzliche Merges, eingeschleuste oder konfliktbehaftete Bäume und später zurückgenommene Fremdpfade bleiben verboten.
- Ein geänderter tatsächlicher PR-Base-/Head-Stand invalidiert betroffene Review-Nachweise. Main-, Fortschritts-, Trust- und Abschlussgrenzen bleiben erhalten.

Nur dieser Vorschlag wird dokumentiert. Die Verhaltensänderung benötigt einen später konkret geprüften und genehmigten begrenzten Gate-Patch.
