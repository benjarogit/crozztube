#!/usr/bin/env node
import fs from 'node:fs';
import { harnessCommands } from './opencode-adapter.mjs';
import path from 'node:path';
import { repository, load, localPath, published, setMode, stopDecision, hookDigest } from './workflow.mjs';

const waitBuffer = new Int32Array(new SharedArrayBuffer(4));
function withReceiptLock(file, action) {
  fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
  const lock = file + '.lock';
  const deadline = Date.now() + 1500;
  while (true) {
    try { fs.mkdirSync(lock, { mode: 0o700 }); break; }
    catch (error) {
      if (error.code !== 'EEXIST' || Date.now() >= deadline) throw Error('Receipt lock unavailable');
      Atomics.wait(waitBuffer, 0, 0, 10);
    }
  }
  try { return action(); }
  finally { fs.rmdirSync(lock); }
}
function receiptLines(file) {
  if (!fs.existsSync(file)) return [];
  const stat = fs.lstatSync(file);
  if (!stat.isFile() || stat.isSymbolicLink()) throw Error('Receipt must be a regular file');
  return fs.readFileSync(file, 'utf8').trim().split('\n').filter(Boolean);
}
function appendReceipt(file, item) {
  return withReceiptLock(file, () => {
    const lines = receiptLines(file);
    // Keep corruption visible. Only explicit archive/retest may recover it.
    for (const line of lines) JSON.parse(line);
    const directory = fs.mkdtempSync(path.join(path.dirname(file), '.hook-receipt-'));
    const temporary = path.join(directory, 'next');
    try {
      const fd = fs.openSync(temporary, 'wx', 0o600);
      try { fs.writeFileSync(fd, [...lines.slice(-499), JSON.stringify(item)].join('\n') + '\n'); fs.fsyncSync(fd); }
      finally { fs.closeSync(fd); }
      fs.renameSync(temporary, file);
    } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  });
}
function archiveCorruptReceipts(file) {
  return withReceiptLock(file, () => {
    const lines = receiptLines(file);
    let malformed = 0;
    for (const line of lines) { try { JSON.parse(line); } catch { malformed++; } }
    if (!malformed) throw Error('No malformed receipt to archive');
    const archive = fs.mkdtempSync(path.join(path.dirname(file), 'hook-receipts-corrupt-'));
    fs.chmodSync(file, 0o600);
    fs.renameSync(file, path.join(archive, 'original.jsonl'));
    return { archived: true, malformedLines: malformed, runtimeEvidenceRestored: false, next: 'Run a fresh real runtime probe after hook trust review.' };
  });
}
let output = {};
let event = process.argv[2];
let policyEvaluated = false;
try {
  const root = repository();
  load(root);
  if (process.argv[2] === '--archive-corrupt-receipts') {
    console.log(JSON.stringify(archiveCorruptReceipts(localPath(root, 'hook-receipts.jsonl'))));
    process.exit(0);
  }
  const input = JSON.parse(fs.readFileSync(0, 'utf8') || '{}');
  event = input.hook_event_name || process.argv[2];
  let decision = 'observed';
  const cursorShell = event === 'beforeShellExecution';
  if (event === 'PreToolUse' || cursorShell) {
    const tool = String(input.tool_name || '');
    const command = cursorShell ? String(input.command || '')
      : input.tool_input?.cmd ?? input.tool_input?.command ?? input.tool_input?.code ?? '';
    const shellTool = cursorShell || /Bash|exec_command|functions\.exec|run_in_terminal/.test(tool);
    const candidates = shellTool ? harnessCommands(command) : { probe: false, transitions: [] };
    if (candidates.probe) {
      decision = 'probe-blocked';
      const reason = 'CROZZTUBE_HARNESS_PROBE_BLOCKED: the project hook intercepted the harmless runtime probe.';
      output = cursorShell
        ? { permission: 'deny', user_message: reason, agent_message: reason }
        : { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason } };
    }
    if (candidates.transitions.length) {
      const context = load(root);
      if (candidates.transitions.some(id => id !== context.step.id + 1) || !published(root, context)) {
        decision = 'advance-blocked';
        const reason = 'CrozzTube harness: the current step is not accepted, cleanly committed and confirmed pushed. Keep it open.';
        output = cursorShell
          ? { permission: 'deny', user_message: reason, agent_message: reason }
          : { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason } };
      }
    }
    if (cursorShell && decision === 'observed') output = { permission: 'allow' };
  } else if (event === 'UserPromptSubmit') {
    const prompt = String(input.prompt || '').trim();
    if (/^(?:warte|stopp?|pause|halt|stop)\b/i.test(prompt)) setMode(root, 'paused', 'Explicit user interruption; await an explicit implementation resume.');
    else if (/[?？]\s*$/.test(prompt) || /^(?:wie|was|warum|wieso|welche|kann|ist|sind|what|why|how)\b/i.test(prompt)) setMode(root, 'discussion', 'Answer the user question without a forced implementation continuation.');
  } else if (event === 'Interrupt') {
    setMode(root, 'discussion', 'Turn interrupted; honor new user input before resuming implementation.');
  } else if (event === 'Stop') output = stopDecision(root, input);

  const digest = hookDigest(root);
  const receiptFile = localPath(root, 'hook-receipts.jsonl');
  policyEvaluated = true;
  appendReceipt(receiptFile, { event, decision,
    session: input.session_id || input.conversation_id || input.generation_id || input.request_id || null,
    at: new Date().toISOString(), hookDigest: digest });
} catch {
  // Do not expose tool arguments, credentials or user prompts in a hook error.
  // A failed hook cannot satisfy runtime-check or the step-completion gate.
  const reason = 'CrozzTube harness hook could not validate its state. Run the harness status/checks and keep the current step open.';
  // Logging failure must never erase a denial already computed above.
  if (policyEvaluated) output = { ...output, systemMessage: reason };
  else if (event === 'PreToolUse') output = { ...output, hookSpecificOutput: output.hookSpecificOutput || { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason }, systemMessage: reason };
  else if (event === 'beforeShellExecution') output = { permission: 'deny', user_message: reason, agent_message: reason };
  else if (event === 'Stop' && output.decision === 'block') output = { ...output, systemMessage: reason };
  else output = { systemMessage: reason };
  if (process.argv[2] === '--archive-corrupt-receipts') process.exitCode = 1;
}
console.log(JSON.stringify(output));
