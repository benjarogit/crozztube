# Bounded hook receipt repair

The owner explicitly authorized the receipt-writer repair, a narrowly bounded
tooling publication exception, one further canonical parent integration and
private corruption archival followed by a real retest. This is not main-merge,
deployment, step-completion or trust approval.

The hook's read/retention/write operation is serialized under an exclusive
directory lock with a bounded wait. Complete JSONL is published with an atomic
rename and restrictive permissions. Existing malformed input is not silently
discarded. A stale lock is not automatically removed. Rename alone does not
establish hard-crash durability.

An already evaluated policy decision survives a receipt I/O error: probe,
advance and Stop denials stay denials; ordinary tool calls retain the original
nonblocking policy result with a warning. This allows the explicitly approved
archive operation to be invoked when the receipt is corrupt. A failure before
policy evaluation still denies PreToolUse/legacy shell requests. Logging errors
never create a probe receipt or satisfy runtime verification.

The explicit `--archive-corrupt-receipts` operation moves the complete malformed
file into a private directory under the existing Git-private harness state.
It outputs no content, session identifiers or private path. It refuses a valid
log and never creates replacement probe evidence. Review hook trust through the
host's normal mechanism and obtain a fresh actually blocked runtime probe;
neither archived bytes nor test fixtures constitute runtime enforcement.

The tooling gate accepts only the unchanged upstream hook blob or the exact
reviewed repair blob, including each historical commit and the original file
mode. Other hook configuration, workflow implementation, plan/progress and
existing feature gates remain outside this exception. The portable fixture test
is mandatory in tooling verification and hosted CI. It uses temporary Git
repositories only, including 24 simultaneous writers, retention, corruption,
archive preservation, current versus changed digest, policy-warning recovery,
lock contention, injected rename failure, invalid input and bounded Stop logic.

No active-runtime success is inferred from these tests. The separate canonical
parent integration must retain all existing historical anchors and bind its
first parent to published feature checkpoint
`dfa0dc1dc43c5774e711846bac7b558f5b530cc8` and its second parent to
the published repair tooling head. No arbitrary merge or conflict resolution is
authorized. Publication, trust review, archive execution and a real runtime
retest must be reported separately after they actually occur.

The fixed preceding integration is
`88e731395587dfba5fa8dbb336fc56fa7008eb2e`, with tooling parent
`ac406456662c78a154b445328e3539ab87468193`. A fourth integration is checked
against the actual published tooling parent and the canonical conflict-free
merge tree. Every prior integration and historical feature path is still
validated. A fifth merge, a different feature anchor, swapped parents or manual
conflict resolution is rejected.
