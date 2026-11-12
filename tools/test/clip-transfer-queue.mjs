import { spawnSync } from 'node:child_process';

const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data',
  'web', 'php', 'tools/test/php/clip-queue-checks.php'], {
  encoding: 'utf8', timeout: 120000, maxBuffer: 1000000,
});
const summary = result.stdout?.match(/(\d+) clip queue checks passed, including independent MySQL writers\./);
if (result.status !== 0 || !summary || Number(summary[1]) < 32) {
  console.error('Clip queue checks failed or did not reach their final assertions. Sensitive database output suppressed.');
  process.exit(1);
}
console.log(`${summary[1]} clip queue checks passed, including independent MySQL writers. Isolated fixture tables removed; no remote uploads.`);
