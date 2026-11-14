# Integration evidence — 2026-11-14

Scope: ChatDev tooling only; no feature acceptance or advancement.

- Baseline: main at 80ed7e34839ac9eb93094900cd91c75013250bf0.
- Assembled local run: 43/43 tests passed (15 existing harness, 17 core, 11 tooling gate).
- Independent agent review: core and gate reviewed separately by chatdev_gate_audit. Findings fixed: readable task retrieval, JSON error redaction, executable-mode digest, unsafe stale-lock recovery removed.
- Actual local CLI setup, validation and role routing executed in the isolated integration checkout. Unit tests use temporary repositories and child-process restarts, not production databases.
- Existing hooks, plan, progress, workflow and Impeccable gate remain unchanged. Existing runtime receipts were not copied.
- Genuine Cursor → Codex → Cursor interactive restarts: NOT VERIFIED. Installed binaries and simulated callers are not proof.
- Hosted CI and publication: consult the PR and confirmed remote commit; not assumed by this document.
- GitHub workflow uses official v4 tag commit hashes resolved with gh on 2026-11-14. It installs pinned/checksum-verified local Gitleaks before tests and has read-only contents permission. No recurring schedule or auto-merge.

Known operational limit: journal identities are advisory actor labels. They are not authentication and never grant a harness, deployment or branch-merge approval. After a killed writer, stop every writer and inspect the lock before manual cleanup.
