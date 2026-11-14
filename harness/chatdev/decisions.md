# Engineering organization decisions

## CD-001 — One organization and one authoritative harness

Date: 2026-11-14. Source: the user's ChatDev integration request; `AGENTS.md`.

Use shared Markdown/JSON instructions, fourteen logical role definitions and a
Node-builtins CLI. Codex and Cursor consume the same material. Keep work journals
under Git's worktree-local private path. Do not add Mem0, Cognee, SQLite, cloud
memory, a model API client, or paid services without demonstrated need.

Reason: existing repository files already carry product/design knowledge and
harness state. A second state machine for feature acceptance would conflict.
Alternative considered: an external agent/memory service; rejected for this
scope because it adds credentials, cost and synchronization without evidence.
Revisit only when a measured requirement cannot be met with local files.

## CD-002 — Reviews and adapters are evidence, not bypasses

Date: 2026-11-14. Sources: `AGENTS.md`, `tools/harness/workflow.mjs`,
`tools/harness/maintenance.mjs`.

The shared organization can prepare and review work, but cannot satisfy an
existing publication gate by its own approval. Native adapter installation and
real application execution must be reported separately. A live two-application
restart/handoff is unverified until actually performed.

The current gate implementation binds ordinary verification/publication to the
plan branch. Its separate maintenance path is exclusively Impeccable. ChatDev
must not repurpose it. Publishing ChatDev from an isolated tooling branch needs
an expressly approved, narrowly scoped publication mechanism. No permission is
inferred to change gates, trust or plan branch. Until then, leave publication
blocked and retain the reviewable changes.

## CD-003 — Separately approved tooling publication gate

Date: 2026-11-14. Source: subsequent explicit user approval for the scoped
ChatDev gate. Supersedes CD-002’s publication blocker only.

Add chatdev-verify/chatdev-checkpoint on codex/chatdev-integration against
published origin/main with fixed path restrictions, exact CLI/reference
appends, unchanged original gates/hooks/progress and snapshot-bound tests.
Independent review remains necessary; no main merge or feature completion is
authorized. See tools/harness/chatdev-maintenance.mjs for enforced scope.
