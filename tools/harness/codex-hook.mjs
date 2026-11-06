#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { repository, load, localPath, published, setMode, stopDecision } from './workflow.mjs';

let output = {};
try {
  const input = JSON.parse(fs.readFileSync(0, 'utf8') || '{}');
  const event = input.hook_event_name || process.argv[2];
  const root = repository();
  load(root);
  let decision = 'observed';
  if (event === 'PreToolUse') {
    const tool = String(input.tool_name || '');
    const command = input.tool_input?.cmd ?? input.tool_input?.command ?? input.tool_input?.code ?? '';
    if (/Bash|exec_command|functions\.exec|run_in_terminal/.test(tool) && /tools\/harness\/cli\.mjs\s+deny-probe(?:\s|$)/.test(command)) {
      decision = 'probe-blocked';
      output = { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: 'CROZZTUBE_HARNESS_PROBE_BLOCKED: the project hook intercepted the harmless runtime probe.' } };
    }
    const transition = /tools\/harness\/cli\.mjs\s+begin\s+(\d+)(?:\s|$)/.exec(command);
    if (transition && /Bash|exec_command|functions\.exec|run_in_terminal/.test(tool)) {
      const context = load(root);
      if (Number(transition[1]) !== context.step.id + 1 || !published(root, context)) {
        decision = 'advance-blocked';
        output = { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: 'CrozzTube harness: the current step is not accepted, cleanly committed and confirmed pushed. Keep it open.' } };
      }
    }
  } else if (event === 'UserPromptSubmit') {
    const prompt = String(input.prompt || '').trim();
    if (/^(?:warte|stopp?|pause|halt|stop)\b/i.test(prompt)) setMode(root, 'paused', 'Explicit user interruption; await an explicit implementation resume.');
    else if (/[?？]\s*$/.test(prompt) || /^(?:wie|was|warum|wieso|welche|kann|ist|sind|what|why|how)\b/i.test(prompt)) setMode(root, 'discussion', 'Answer the user question without a forced implementation continuation.');
  } else if (event === 'Interrupt') {
    setMode(root, 'discussion', 'Turn interrupted; honor new user input before resuming implementation.');
  } else if (event === 'Stop') output = stopDecision(root, input);

  const hookDigest = createHash('sha256').update(fs.readFileSync(path.join(root, '.codex/hooks.json'))).update(fs.readFileSync(path.join(root, 'tools/harness/codex-hook.mjs'))).update(fs.readFileSync(path.join(root, 'tools/harness/workflow.mjs'))).digest('hex');
  const receiptFile = localPath(root, 'hook-receipts.jsonl');
  fs.mkdirSync(path.dirname(receiptFile), { recursive: true, mode: 0o700 });
  const previous = fs.existsSync(receiptFile) ? fs.readFileSync(receiptFile, 'utf8').trim().split('\n').filter(Boolean).slice(-499) : [];
  previous.push(JSON.stringify({ event, decision, session: input.session_id || null, at: new Date().toISOString(), hookDigest }));
  fs.writeFileSync(receiptFile, previous.join('\n') + '\n', { mode: 0o600 });
} catch {
  // Do not expose tool arguments, credentials or user prompts in a hook error.
  // A failed hook cannot satisfy runtime-check or the step-completion gate.
  output = { systemMessage: 'CrozzTube harness hook could not validate its state. Run the harness status/checks and keep the current step open.' };
}
console.log(JSON.stringify(output));
