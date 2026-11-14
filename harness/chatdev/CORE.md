# CrozzTube shared engineering organization

Read this file from both Codex and Cursor. It is a shared instruction layer,
not a replacement for `AGENTS.md` or the harness.

## Start every session

1. Read `AGENTS.md`, `PRODUCT.md`, `DESIGN.md`, `harness/plan.json`,
   `harness/progress.json`, and `HANDOFF.md`. Historical handoff statements never
   override the live harness files.
2. Inspect branch, HEAD, working tree and remotes. Run
   `node tools/harness/cli.mjs status` and `node tools/chatdev/cli.mjs context`.
3. Read `harness/chatdev/roles.json`, `facts.md`, `decisions.md`, and the local
   task journal. Confirm sources still agree with the current checkout.
4. Preserve unknown files and other worktrees. Never read credentials for a
   documentation or orchestration task. Never put secrets, tokens, runtime
   receipts, user content or raw prompts in the journal.

## Work cycle

Authorized objective → inventory → analysis → bounded plan → role selection →
implementation → tests → independent review → security/architecture checks →
existing harness gate → documentation → handoff.

Use the smallest suitable group of roles. The fourteen definitions are logical
responsibilities. Spawn real agents only when tools and authorization allow;
otherwise execute explicit separate passes. Never claim independent processes
or platform execution that did not occur. Independent review requires a separate
reviewer and evidence tied to the reviewed source, not the engineer approving
its own task. A review is not an approval to deploy or bypass a harness gate.

The local journal records the objective, owning role, reviewers, changed paths,
risks, dependencies, acceptance criteria, actual checks, review findings, open
approvals and next action. Its step reference is context only: read the actual
step and acceptance state from the harness every time. Source changes require a
new review. Record failed or unavailable tests honestly.

## Authority and boundaries

- `harness/plan.json` owns step sequence and required checks;
  `harness/progress.json` owns progress and acceptance. Only existing harness
  commands can advance or accept a step. ChatDev cannot grant approvals.
- Preserve hooks, Git-LFS hooks, runtime trust and negative gates byte-for-byte
  unless a separately approved change explicitly covers them. Never fabricate
  runtime receipts or manually edit progress to unblock publication.
- The ChatDev setup task adds tooling only. It authorizes no series/seasons or
  later product feature development, production changes or new paid services.
- Keep creator/admin content, provider behavior, captions, audio, chapters,
  permissions, ads, external image hosting and publishing intact. Clips are cut
  in the browser; video bytes never pass through the application server.
- Production deployment/data changes, destructive operations, security/gate
  changes, major product-impacting architecture, paid services and protected
  branch merges require the applicable explicit approval.

## Persistent knowledge and handoff

Version reusable facts and decisions in the two adjacent Markdown files. Each
entry needs a source, review date and a supersession note when obsolete. Do not
copy current step status, hook receipts or credentials into them. Local task
journals are worktree-specific under Git's private path; update them through the
CLI's lock and revision checks, not hand edits. A conflicting write must fail
rather than overwrite another role's work. Do not delete a stale lock until its
writer is confirmed stopped and the interrupted task is documented.

At handoff, report actual changes, exact checks/results, independent findings,
remaining approvals, publication status and the next permitted action. Commit,
push and PR are distinct facts. No automatic merge. A clean checkout, installed
CLI or adapter file alone does not prove a live Cursor↔Codex restart test.
