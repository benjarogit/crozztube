# CrozzTube implementation contract

Read `PRODUCT.md`, `DESIGN.md`, `harness/plan.json` and `harness/progress.json`.
The user's approved plan is implemented in order, one active step at a time.

## Workflow

- Use `node tools/harness/cli.mjs status` before implementation.
- Use `resume` for an implementation turn; use `mode discussion|paused|blocked
  --reason "..."` for questions, an explicit pause or a real external blocker.
- Do not start, delegate or edit a later feature while the current step is open.
- After a meaningful tested change, stage only reviewed paths, run `verify`,
  then `checkpoint --message "..."` to commit and push the checked snapshot.
- Complete a step using `complete --message "..." --note "acceptance evidence"`.
  This requires passing checks bound to the current files, a cleanly staged
  snapshot, real runtime-hook evidence and confirmed publication to the remote.
- Only `begin N` may advance the active step. It requires the preceding step's
  accepted, committed and published state. Never edit progress to bypass it.
- Configure meaningful checks and acceptance evidence for each step before
  completing it. Missing credentials, failing tests or failed pushes keep it open.
- Report the result, checks, commit and confirmed push. Never claim a live
  integration was tested based only on mocks or a configuration file.

## Preservation and safety

- Preserve existing user changes and administrator/creator content. The baseline
  commit is a checkpoint, not full linguistic acceptance of all language packs.
- Credentials, environment files, certificates, private keys and runtime receipts
  stay outside Git. Never print secret values in command output or test reports.
- Never force-push, rewrite existing history or replace shared Git-LFS hooks.
- Clips are cut in the browser. Video bytes must not be stored, transcoded or
  proxied by the CrozzTube application server. R2 is external staging only.
- Keep generic local video-file uploads disabled. Preserve permissions, ads,
  provider content, original audio, captions, chapters and mounted player/forms.
- Use the relevant project language/design skills for UI/copy changes.
- Use bounded media traffic and one browser-test worker.
- A project hook must be loaded by the active agent runtime and observed firing before runtime
  enforcement is claimed. Never bypass hook trust to satisfy this acceptance.
