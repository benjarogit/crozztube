# Proposed separation of product acceptance and editor integration

Status: proposal only, 2026-11-15. No gate is changed by this document.

## Origin and scope

The original ChatDev setup request explicitly requested a real
Cursor → shared state → Codex → shared state → Cursor handoff (section
“Umgebungswechsel”). Its test section also explicitly allowed unavailable tests
to be recorded as not verified; fixtures must never be called a real runtime
proof. That request concerned the shared ChatDev setup, not series playback.

Commit f837a29 introduced CD-004 and tools/chatdev/pr-audit.mjs. CD-004
limited the cross-client acceptance to “this integration PR”. The evaluator
instead applied it to every PR: runtime.required=false is rejected and any
missing/unverified runtime prevents READY. This generalized an integration
prerequisite into an unrelated product PR condition.

The authoritative step-8 plan lists only series extension of existing playlists,
series-context episode metadata and next-episode access/autoplay/queue priority.
It contains no Cursor prerequisite. AGENTS.md and harness step 1 separately
require actual trusted Codex hooks; that requirement must remain unchanged.

The owner's current clarification separates normal CrozzTube work from future
editor integration in Orqen. The historical requested handoff remains honestly
not verified; it must not be turned into a fictitious pass or silently erased.

## Proposed bounded correction requiring separate gate approval

1. In tools/chatdev/pr-audit.mjs remove only the universal cross-editor blocker
   (the two runtime prerequisite conditions). Return a separate editorIntegration
   report: verified only for actual current-head runtime evidence, otherwise
   not-verified; historical/unavailable/simulated evidence never becomes PASS.
   This report does not participate in product READY/BLOCKED decisions.
2. Replace the corresponding runtime-gate expectations in
   tools/test/chatdev-pr.test.mjs with positive/negative separation tests:
   no editor installed does not block an otherwise valid product PR;
   missing/current-head/blank/simulated editor evidence is not mislabeled;
   failed/missing/stale CI, independent reviews, comment triage and source-bound
   tests still block exactly as before. Keep all unrelated tests unchanged.
3. Supersede only CD-004's integration-as-global-gate interpretation in
   harness/chatdev/decisions.md; align CORE/README and existing verification
   documentation. Do not claim runtime compatibility in PR descriptions.
4. Preserve plan/progress, real Codex-hook enforcement/trust, step sequencing,
   permissions, path/provenance guards, secret checks, publication receipts,
   CI, independent review and merge/deployment authorization byte-for-byte.
5. Use the existing tooling gate, independent review, confirmed push and hosted
   CI. Reassess PR #3 and #4 from actual evidence; no automatic merge, retarget,
   deployment or step completion is authorized by this correction.

## Separate existing parent-publication constraint

The published tooling parent advanced to d28e865, while the feature's last
approved integration contains be06d13. Existing seriesScope requires the current
published parent as an ancestor, but allows only two specifically pinned
canonical integrations, both already used. Consequently new feature work may be
prepared, but cannot obtain a series checkpoint under the current rule.

If separately authorized, add exactly one additional canonical conflict-free
parent integration at feature checkpoint
65c04875241caede38045440446af44054059df6. Retain validation of both historical
integrations, exact first parent, published same-repository tooling ancestry,
canonical merge tree, path/history/secret restrictions, mandatory source-bound
feature checks and confirmed push. All other third merges, manual resolutions,
injected/reverted foreign paths or later feature heads remain rejected.
The tooling parent must be the actually published reviewed snapshot containing
this narrowly approved correction; no arbitrary local commit can substitute.
This proposes a branch integration only, never a merge into main. Without this
separate permission the constraint remains, rather than being bypassed.
