import { spawnSync } from 'node:child_process';
import path from 'node:path';

/**
 * Project-local OpenCode adapter for the CrozzTube harness.
 *
 * OpenCode V2 evaluates `ctx.permission.hook("evaluate")` after its configured
 * rules and before a shell action runs. The adapter forwards only the two
 * gate-critical harness commands to the existing `codex-hook.mjs` contract:
 * the harmless `deny-probe` and a forward step transition (`begin N`).
 * Everything else stays untouched, so normal shell work is not delayed.
 */
export const ADAPTER_DENIAL = 'CrozzTube harness: the project hook could not validate this runtime command. Keep the current step open.';

const PROBE = /(?:^|[\s"'`;&|(])deny-probe(?=$|[\s"'`;&|)])/;
const BEGIN = /tools[\\/]harness[\\/]cli\.mjs[\s"'`]*\s+begin\s+\d+(?=$|[\s"'`;&|)])/;

export function shouldEvaluate(command) {
  if (typeof command !== 'string' || !command.trim()) return false;
  // The hook itself decides whether the probe or the transition is allowed.
  // The adapter never turns a non-match into a denial.
  return PROBE.test(command) || BEGIN.test(command);
}

export function evaluateHarnessCommand({
  root,
  command,
  sessionID,
  hookPath = path.join(root, 'tools/harness/codex-hook.mjs'),
  node = process.env.CROZZTUBE_NODE || 'node',
  timeout = 5000,
} = {}) {
  if (!root || !shouldEvaluate(command)) return { deny: false };
  const result = spawnSync(node, [hookPath, 'beforeShellExecution'], {
    cwd: root,
    input: JSON.stringify({
      hook_event_name: 'beforeShellExecution',
      command,
      session_id: sessionID || null,
    }),
    encoding: 'utf8',
    timeout,
    maxBuffer: 64 * 1024,
    env: { ...process.env, CROZZTUBE_HARNESS_ADAPTER: 'opencode' },
  });
  if (result.error || result.status !== 0) return { deny: true, message: ADAPTER_DENIAL };
  const line = String(result.stdout || '').trim().split('\n').filter(Boolean).pop();
  let output;
  try { output = JSON.parse(line); } catch { return { deny: true, message: ADAPTER_DENIAL }; }
  if (output?.permission !== 'deny') return { deny: false };
  return {
    deny: true,
    message: String(output.agent_message || output.user_message || ADAPTER_DENIAL),
  };
}
