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

## CD-004 — PR decisions and runtime acceptance (2026-11-14)

The orchestrator must continue after PR publication: collect exact-head GitHub
checks and every review/comment/thread, triage, delegate fixes, consume returned
evidence, re-test, publish and reassess until READY or a concrete blocker.
A CodeRabbit Free summary is not substantive independent approval.

The original cross-client acceptance remains required for this integration PR:
the deliverable claims shared automatically loaded instructions and handoff in
both environments. Child-process fixture tests alone do not establish that.
Lack of an automatable Cursor agent is an external verification blocker, not
permission to change runtime.required to false. No main merge is authorized.

Sources: original user integration acceptance; follow-up PR-orchestration audit
request; actual installed command inventory and read-only Codex CLI probe.

## CD-005 — Bounded step-8 feature publication (2026-11-14)

The owner explicitly approved a separate feature-PR checkpoint path after PM
validation. series-verify and series-checkpoint apply only to active step8 on
codex/series-seasons, stacked on the freshly verified published
codex/chatdev-integration parent. This allows independent series implementation
without merging unaccepted tooling into main. Existing checkpoint checks remain
mandatory. Only the real step-08-series check may be added to the plan; all
existing requirements and progress are immutable. Release assets must be
indexed; receipts bind all remote destinations. Completion/begin and main merges
remain outside this path and retain the original requirements.

## CD-006 — Owner-approved editor/product scope correction (2026-11-15)

Source: the owner's explicit editor-independence clarification and subsequent
separate bounded gate approval in this session. Supersedes CD-004's cross-editor
PR prerequisite and its accidental generic application; keeps its CI/review loop.

The original setup requested a real cross-client test but allowed unavailable
tests to stay not verified. f837a29's evaluator applied that integration-specific
requirement to every PR; step 8's authoritative three product criteria contain
no Cursor requirement. CrozzTube development does not need a controllable Cursor.
Editor interoperability is separately owned and validated in Orqen. Report it
as not verified; no simulated or caller-asserted editor flag makes it pass.
No new orchestration implementation or editor runtime is installed here.

This correction removes only the two universal cross-editor audit blockers.
Exact-head tests/reviews, GitHub checks/comments, secret/path/provenance safety,
confirmed publication, real Codex-hook enforcement, step completion and merge
permissions remain unchanged. READY is advisory, never merge or deployment.

The owner separately authorized one further canonical, conflict-free published
ChatDev-parent integration at feature head 65c0487. Preserve both prior canonical
integrations; reject a fourth, foreign history, injected trees, unapproved feature
heads and manual conflict resolution. No main merge or step 9 is authorized.
