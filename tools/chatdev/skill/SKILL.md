---
name: chatdev
description: Coordinate CrozzTube engineering with its existing ChatDev roles, Project Manager, shared task journal and PR review workflow. Use when asked to invoke ChatDev, resume its tasks, coordinate roles or assess CrozzTube PR readiness. Does not replace the project harness or grant merge permission.
---

# CrozzTube ChatDev

Use the existing implementation in the current repository. First confirm that
`tools/chatdev/cli.mjs`, `harness/chatdev/CORE.md`, `harness/plan.json` and
`harness/progress.json` exist. If any are missing, stop and explain that this
checkout does not contain ChatDev yet; do not switch branches, merge or create
another implementation.

1. Read `AGENTS.md` and `harness/chatdev/CORE.md`, then their required project
   sources and `harness/chatdev/roles.json`. Follow the current checkout's rules.
2. Run `node tools/harness/cli.mjs status`,
   `node tools/chatdev/cli.mjs context` and
   `node tools/chatdev/cli.mjs project-manager`. Reconcile their actual results
   before selecting work. Never infer progress from a previous conversation.
3. For implementation, use the existing harness resume and scoped publication
   commands. Work only on the authorized current step or explicit tooling
   exception. For read-only requests, inspect without changing the journal.
4. Use `node tools/chatdev/cli.mjs route <repo-relative-path...>` to select
   relevant logical roles. Read `tools/chatdev/README.md` for task, review and
   revision-checked journal commands. Do not invent commands or duplicate state.
5. Use real independent reviewers when required and available. Distinguish
   logical role passes from actual agent executions. Do not claim to control an
   unavailable Cursor or Codex agent.
6. After a PR change, keep coordinating CI and review triage in the active
   session using the existing PR audit and Project Manager. Follow CORE's
   exact-head evidence requirements. Report READY, CHANGES_REQUIRED or BLOCKED
   with concrete evidence; this is never permission to merge or advance a step.

Preserve user changes, hooks and trust, credentials, feature gates and existing
content. Never print secrets or fabricate runtime evidence. Report checks,
independent review, remaining blockers, commit and confirmed push separately.
This skill is an entry point, not a background scheduler or a second runtime.
