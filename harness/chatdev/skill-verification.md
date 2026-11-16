# Native ChatDev skill verification

Observed on 2026-11-14. This record concerns skill discovery, not feature
acceptance or the still unavailable Cursor → Codex → Cursor restart test.

## Cause and installation

The original CLI and always-apply Cursor rule had no native `SKILL.md` entry.
The single canonical entry is now `tools/chatdev/skill/SKILL.md`, with Codex
picker metadata in `skill/agents/openai.yaml`. `install-skill` materializes it
at the shared local user root `~/.agents/skills/chatdev/` without changing any
project gate, hook, feature checkout, plan or progress file. The command is
explicit; journal `setup` never silently modifies user skills.

Official discovery/invocation references:
- [Codex skills](https://learn.chatgpt.com/docs/build-skills)
- [Cursor skills](https://cursor.com/help/customization/skills)

## Directly observed

- Installed `codex-cli 0.166.0` app-server accepted `skills/list` with a forced
  rescan for both the ChatDev tooling checkout and series/seasons checkout.
  Each returned exactly one `chatdev`, `scope: user`, `enabled: true`, the
  installed SKILL.md path, parsed picker metadata, and no ChatDev parser errors.
- An actual ephemeral `codex exec --sandbox read-only` request explicitly using
  `$chatdev` read the installed skill and ran the existing ChatDev context and
  Project Manager. These reported the current active step 8 and existing roles.
  This is an observed agent invocation, not only a manually parsed YAML fixture.
- In that restricted child runtime, harness status failed with `spawnSync git
  EPERM`. It was reported as failed rather than suppressed or worked around.
  The parent session's normal harness status succeeded. Thus complete execution
  of every startup command in that restricted child is **not** claimed.
- Node installer tests cover initial installation, repeat no-op, successful
  update, local-edit refusal, foreign registrations, symlinks, concurrent lock
  rejection and rollback/retry after a simulated publication failure.

Raw runtime output remains outside Git. No provider credentials were required.
The user install is machine-local; new sessions can rescan it. Existing Desktop
picker refresh was not directly observed.

## Still open

Cursor-Agent is unavailable. Its documented discovery layout is satisfied, but
actual Cursor picker discovery, `/chatdev` execution and the bidirectional
Cursor/Codex journal handoff remain **not verified**. The existing runtime
blocker stays open. Cloud/remote worker propagation was not configured or tested.

The global skill does not put ChatDev code into old main or another checkout.
It explicitly stops if the current checkout lacks the existing core/CLI/harness.
No source integration, merge, step completion or step 9 was performed.

### Scope supersession, 2026-11-15

The owner explicitly separated editor interoperability into Orqen and approved
removing the generic cross-editor PR blocker. The historical observations above
remain valid and not verified; they no longer block CrozzTube product work.
Real active-project Codex-hook enforcement remains in the existing harness.
See CD-006; this does not claim an Orqen implementation or editor execution.
