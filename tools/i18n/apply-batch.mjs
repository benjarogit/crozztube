import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const file = process.argv[2];
if (!file) throw new Error('Usage: node tools/i18n/apply-batch.mjs BATCH.json [--apply]');
const batch = JSON.parse(fs.readFileSync(file, 'utf8'));
if (!batch.context?.trim() || !batch.messages || typeof batch.messages !== 'object') throw new Error('A reviewed batch needs concrete context and complete messages.');
const container = process.env.WEB_CONTAINER || 'crozztube-web';
const docker = args => execFileSync('docker', args, { cwd: root, encoding: 'utf8', maxBuffer: 100 * 1024 * 1024 });
const reference = docker(['exec', container, 'php', 'tools/i18n/export.php']);
const packs = JSON.parse(reference);
const snapshot = `/tmp/crozztube-review-${randomUUID()}.json`;
const apply = process.argv.includes('--apply');
try {
  execFileSync('docker', ['exec', '-i', container, 'tee', snapshot], { input: reference, stdio: ['pipe', 'ignore', 'pipe'] });
  for (const locale of Object.keys(packs).sort((a, b) => a === 'en' ? -1 : b === 'en' ? 1 : a.localeCompare(b))) {
    const translations = {};
    for (const [message, values] of Object.entries(batch.messages)) {
      const value = values[locale] ?? values[locale.split('_')[0]];
      if (value !== undefined) translations[message] = value;
    }
    const metadata = batch.metadata?.[locale] ?? batch.metadata?.[locale.split('_')[0]];
    if (!Object.keys(translations).length && !metadata) continue;
    const args = ['exec', '-i', container, 'php', '-d', 'display_errors=stderr', 'tools/i18n/apply-reviewed.php', `--locale=${locale}`, '--translations=php://stdin', `--reference=${snapshot}`, `--review-context=${batch.context}`];
    if (metadata) {
      execFileSync('docker', ['exec', '-i', container, 'tee', `${snapshot}.metadata`], { input: JSON.stringify(metadata), stdio: ['pipe', 'ignore', 'pipe'] });
      args.push(`--metadata=${snapshot}.metadata`);
    }
    if (apply) args.push('--apply');
    process.stdout.write(execFileSync('docker', args, { cwd: root, encoding: 'utf8', input: JSON.stringify(translations) }));
  }
} finally { docker(['exec', container, 'rm', '-f', snapshot, `${snapshot}.metadata`]); }
