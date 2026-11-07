import { spawnSync } from 'node:child_process';
const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data',
  'web', 'php', 'tools/test/php/clip-confirmation-checks.php'], { encoding: 'utf8', timeout: 120000, maxBuffer: 1000000 });
const summary = result.stdout?.match(/(\d+) clip confirmation checks passed\./);
if (result.status !== 0 || !summary || Number(summary[1]) < 42) {
  console.error('Clip confirmation checks failed; sensitive database diagnostics suppressed.'); process.exit(1);
}
console.log(`${summary[1]} clip confirmation checks passed. Controlled delays, rights, exactly-once notifications and cleanup; isolated MySQL fixtures.`);
