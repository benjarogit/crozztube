import { spawnSync } from 'node:child_process';

for (const [file, label, pattern] of [
  ['auf1-provider-checks.php', 'AUF1 source renewal', /AUF1 checks: (\d+) passed, (\d+) failed/],
  ['odysee-audio-checks.php', 'Original and alternate audio', /(\d+) passed; (\d+) failed/],
  ['image-upload-edge-checks.php', 'Image upload edge cases', /Image upload edge checks: (\d+) pass, (\d+) fail/],
]) {
  const result = spawnSync('docker', ['exec', 'crozztube-web', 'php', `tools/test/php/${file}`], {
    encoding: 'utf8', timeout: 120_000, maxBuffer: 3_000_000,
  });
  const summary = result.stdout?.match(pattern);
  if (result.status !== 0 || !summary || Number(summary[1]) === 0 || Number(summary[2]) !== 0) {
    console.error(`${label} failed or did not reach its final assertions. Raw output suppressed to protect credentials.`);
    process.exit(1);
  }
  console.log(`${label}: ${summary[1]} pass, 0 fail. Mock upstream only; no external media requests.`);
}
