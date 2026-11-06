#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { repository, git, load, localState, setMode, published, verify, begin, publish, runtimeEvidence } from './workflow.mjs';

const [command = 'status', ...args] = process.argv.slice(2);
const option = key => args[args.indexOf(key) + 1] && args.includes(key) ? args[args.indexOf(key) + 1] : '';
try {
  const root = repository();
  if (command === 'status') {
    const context = load(root);
    console.log(JSON.stringify({ activeStep: context.step.id, title: context.step.title, status: published(root, context) ? 'complete' : context.record.status === 'accepted' ? 'awaiting-confirmed-publication' : context.record.status, mode: localState(root), runtimeHookVerified: runtimeEvidence(root), branch: git(root, ['branch', '--show-current']) }, null, 2));
  } else if (command === 'resume') setMode(root, 'implementing');
  else if (command === 'mode') setMode(root, args[0], option('--reason'));
  else if (command === 'verify') verify(root);
  else if (command === 'begin') begin(root, Number(args[0]));
  else if (command === 'checkpoint' || command === 'complete') publish(root, option('--message'), { complete: command === 'complete', note: option('--note') });
  else if (command === 'baseline-check') {
    const { plan } = load(root);
    git(root, ['merge-base', '--is-ancestor', plan.baselineCommit, 'HEAD']);
    const changed = git(root, ['diff', '--name-only', plan.baselineCommit, '--']).split('\n').filter(Boolean);
    if (changed.some(file => !/^(?:AGENTS\.md|\.codex\/|harness\/|tools\/harness\/|tools\/test\/harness\.test\.mjs)$/.test(file) && !/^(?:\.codex\/|harness\/|tools\/harness\/)/.test(file))) throw Error('Application baseline changed; rerun the affected application regressions before accepting step 1.');
    if (!fs.existsSync(path.join(root, 'harness/baseline.md'))) throw Error('Baseline evidence missing.');
    console.log('PASS: the checked application baseline is preserved; only harness files changed.');
  } else if (command === 'runtime-check') {
    if (!runtimeEvidence(root)) throw Error('Runtime probe not observed. Review/trust project hooks in Codex, then execute the deny probe.');
    console.log('PASS: a real Codex PreToolUse hook blocked the current runtime probe.');
  } else if (command === 'deny-probe') {
    throw Error('The deny probe executed: the Codex hook did not intercept it. Step 1 remains open.');
  } else throw Error('Unknown command. Use status, resume, mode, verify, checkpoint, complete, begin, baseline-check or runtime-check.');
} catch (error) {
  console.error(`Harness: ${error.message}`);
  process.exitCode = 1;
}
