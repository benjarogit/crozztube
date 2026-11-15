# Exact hook index binding — bounded follow-up decision

The independently reviewed published repair at 2a8273f fixes the receipt race
and denial retention. Its canonical feature integration is 27de4ee; both current
commits contain the correct executable hook blob. The active runtime probe and
unchanged strict validator actually passed. Those results are not withdrawn.

A later independent review reproduced a separate P2 publication-guard gap in
temporary repositories: with core.filemode=false, an index mode of 100644 can be
hidden behind the reviewed 100755 working tree. With skip-worktree, a different
staged hook blob can also be hidden from git diff. Published chatdevScope and
assertStaged accepted those adversarial fixtures. Neither configuration nor
index of the actual project was changed, and no affected published commit is
claimed. Nevertheless the exact-index guarantee is incomplete: PR #3 is not
READY until the counterexample is fixed and published with current evidence.

The concrete prepared correction requires the one stage-0 index entry to match
the already authorized working-tree hook blob and original Git mode exactly.
Existing current/historical blob allowlists and regular-file checks remain.
Two negative regression groups cover the hidden mode and hidden blobs using
skip-worktree/assume-unchanged. Positive reviewed-hook tests explicitly stage
their fixture. All eighteen focused maintenance tests passed locally. At the
pre-publication review snapshot, no hook byte, runtime parser, trust,
hook configuration, plan or progress change is needed.

The previously approved fourth parent integration has already been consumed
by the exact canonical merge 27de4ee. Updating the tooling parent again without
an approved further integration would prevent later series checkpoints under
the existing ancestry gate. Do not publish first and then bypass that gate.

The owner explicitly approved the minimal index correction and exactly one
fifth canonical parent integration. This authorizes implementation and bounded
publication, not a main merge or a broader workflow redesign. Its first parent
is 27de4ee67f71dd1cf5d15d1fc0f5b976cab18d7c and its second parent is the
reviewed, tested and actually published follow-up tooling commit. Pin the fourth
merge 27de4ee and its tooling parent 2a8273f6054627a4e50e9a89de8a927c9402b8c1,
preserve all earlier anchors and reject a sixth merge, changed feature head,
reversed parents, injected trees and manual conflict resolutions. Require
positive/negative regressions, independent review, full existing verification,
confirmed push and hosted CI. The source change fixes all four historical merge
identities and checks the next second parent against the actually published
tooling parent. Its first parent remains exactly the already published fourth
merge; no extra feature checkpoint or arbitrary intermediate commit is allowed.
Synthetic fixtures exercise the real history/path inspection, including rejected
sixth merges, wrong/swapped/stale parents, injected trees, manual conflict
resolution, missing historical identities and foreign changes later reverted.
Execution, exact heads, reviews, CI and remote confirmation are recorded in the
existing private journal and PR after they actually occur. The behavior proposal
in parent-base-stability-proposal.md remains separate and is not implemented
by this fifth-integration authorization.

This is a narrow repair-publication decision, not permission to merge main,
deploy, accept step 8, begin step 9 or add Orqen infrastructure. Once the guard
fix is published and integrated, PR #3 must be reassessed at its new exact head.
Main merge still separately requires explicit owner authorization and manual
confirmation of protection/ruleset requirements unreadable through the API.
