import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { repository, files, load } from './workflow.mjs';

const root = repository();
load(root);
execFileSync('git', ['diff', '--cached', '--check'], { cwd: root, stdio: 'inherit' });
for (const file of files(root)) {
  if (!fs.existsSync(path.join(root, file))) continue;
  if (file.startsWith('tools/harness/') && file.endsWith('.mjs')) execFileSync('node', ['--check', file], { cwd: root, stdio: 'inherit' });
  if (['.codex/hooks.json', '.cursor/hooks.json'].includes(file) || file.startsWith('harness/') && file.endsWith('.json')) JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));
}
execFileSync('node', ['tools/harness/scan-secrets.mjs'], { cwd: root, stdio: 'inherit', timeout: 180_000 });
console.log('PASS: staged whitespace, harness syntax, JSON schemas and staged secret checks.');
