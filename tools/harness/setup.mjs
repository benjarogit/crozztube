#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { repository, localPath } from './workflow.mjs';

if (process.platform !== 'linux' || process.arch !== 'x64') throw Error('Install Gitleaks 8.30.1 for this platform and set GITLEAKS_BIN. Automatic setup currently supports Linux x64.');
const root = repository();
const directory = localPath(root, 'bin');
fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
const name = 'gitleaks_8.30.1_linux_x64.tar.gz';
const base = 'https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/';
const get = async file => {
  const response = await fetch(base + file, { signal: AbortSignal.timeout(60_000) });
  if (!response.ok) throw Error('Could not obtain the pinned Gitleaks release.');
  return Buffer.from(await response.arrayBuffer());
};
const checksums = (await get('gitleaks_8.30.1_checksums.txt')).toString();
const checksum = checksums.split('\n').find(line => line.endsWith(name))?.split(/\s+/)[0];
const archive = await get(name);
if (!checksum || createHash('sha256').update(archive).digest('hex') !== checksum) throw Error('Gitleaks release checksum mismatch.');
const temporary = path.join(directory, name);
fs.writeFileSync(temporary, archive, { mode: 0o600 });
try {
  execFileSync('tar', ['-xzf', temporary, '-C', directory, 'gitleaks']);
  fs.chmodSync(path.join(directory, 'gitleaks'), 0o700);
} finally { fs.rmSync(temporary, { force: true }); }
console.log('Installed checksum-verified Gitleaks 8.30.1 in Git-local tooling.');
