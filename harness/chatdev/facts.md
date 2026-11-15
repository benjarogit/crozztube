# Sourced project facts

Reviewed: 2026-11-14. Recheck these sources after dependency or policy changes.
These entries are not a copy of the current work status.

- CrozzTube is an existing external-source video portal with viewer, creator
  and administrator surfaces. Source: `PRODUCT.md`.
- Declared backend requirements include PHP ^8.3 and Laravel ^13.0; frontend
  declarations include React ^19.1.0 and Vite ^7.0.0. These are manifest ranges,
  not claims about running service or installed package versions. Sources:
  `composer.json`, `package.json`; use lockfiles for resolved versions.
- The harness owns ordered development and verified publication. Sources:
  `AGENTS.md`, `harness/README.md`, `tools/harness/workflow.mjs`.
- The UI has paired light/dark roles with System as the platform default.
  Source: `DESIGN.md`, `app/Support/InterfacePalette.php`.
- Browser clip encoding and external staging/publishing must not become PHP
  video proxying, local storage or server-side transcoding. Source: `AGENTS.md`,
  `harness/plan.json`.
- Handoff material contains historical resolver and operational information.
  Its historical step labels cannot supersede live harness JSON. Sources:
  `HANDOFF.md`, `harness/progress.json`.

Add reusable, non-sensitive facts with source paths. Mark replaced facts as
superseded; keep current task outcomes in the local journal and authoritative
step outcomes in the harness.
