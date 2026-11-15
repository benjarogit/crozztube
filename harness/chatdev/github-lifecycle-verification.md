# GitHub lifecycle verification

Reviewed scope, 2026-11-15: existing ChatDev tooling only. Feature checkout
`codex/series-seasons` remains at published `65c0487`; step 8 stays active.
No feature, gate, hook, progress or trust file changes accompany this increment.

## Actual execution

The new `github-sync 3` CLI path was executed against the real repository while
preparing this increment. Its paginated inventory found zero open issues and
three open PRs: #3 against main, #4 against codex/chatdev-integration and #5
against main. The same inventory appeared in `projectManagement.github`.
This proves the CLI-to-Project-Manager handoff and real read path. It does not
prove a real issue was closed: no suitable actual issue existed. No synthetic
GitHub issue was created to manufacture a success claim.

Mutation fixtures exercise fully evidenced closure, missing criteria, stale
body hashes, incomplete/unavailable checks, pending approvals, failed task
review, unconfirmed push, unmerged dependencies, user-owned marker comments,
idempotent retries, duplicate own markers, API failures and concurrent edits.
These are controlled transport tests, separate from live GitHub writes.
Publication and hosted CI evidence are recorded in the PR and private journal
at the actual published head, not predicted here.

## Remaining boundaries

No PR is automatically merged, closed, retargeted or marked ready by this code.
The live feature PR remains a draft with genuine runtime and language questions
open. The 159 pending messages remain individually described in the feature's
`docs/series-seasons.md`; this tooling change neither translates nor accepts
them. A refreshed local inventory still found only the Cursor GUI launcher,
not a controllable Cursor agent; the real cross-client roundtrip stays open.
PR #3 must precede #4. PR #5 is independent and its absent hosted checks are
not treated as passing. An issue closure is not harness acceptance or a new
permission to merge, deploy or begin step 9.
