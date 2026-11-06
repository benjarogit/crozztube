import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';

// Scan the exact staged snapshot, including unchanged tracked files. Never scan
// the user's ignored environment or print findings containing credential values.
const root = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim();
const local = path.resolve(root, execFileSync('git', ['rev-parse', '--git-path', 'crozztube-harness'], { encoding: 'utf8' }).trim());
const executable = process.env.GITLEAKS_BIN || path.join(local, 'bin/gitleaks');
if (!fs.existsSync(executable)) {
  throw new Error('Install Gitleaks (see harness/README.md), or set GITLEAKS_BIN to its executable.');
}
fs.mkdirSync(local, { recursive: true, mode: 0o700 });
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-index-scan-'));
fs.chmodSync(temporary, 0o700);
const reportPath = path.join(local, 'secret-scan.json');
try {
  execFileSync('git', ['checkout-index', '--all', `--prefix=${temporary}/`], { cwd: root, stdio: 'pipe' });
  const result = spawnSync(executable, ['dir', temporary, '--config', path.join(temporary, '.gitleaks.toml'), '--no-banner', '--redact', '--report-format', 'json', '--report-path', reportPath], {
    cwd: root, encoding: 'utf8', maxBuffer: 10_000_000, timeout: 180_000,
  });
  if (fs.existsSync(reportPath)) fs.chmodSync(reportPath, 0o600);
  if (result.status !== 0) {
    if (fs.existsSync(reportPath)) {
      const findings = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
      for (const finding of findings) console.error(`${finding.RuleID}: ${path.relative(temporary, finding.File)}:${finding.StartLine} (value redacted)`);
    }
    throw new Error(`Secret scan failed (${result.status ?? result.error?.code ?? 'unknown'}). Inspect only the protected, redacted Git-local report.`);
  }
  console.log('PASS: Gitleaks found no secrets in the exact staged snapshot.');
} finally {
  fs.rmSync(temporary, { recursive: true, force: true });
}
