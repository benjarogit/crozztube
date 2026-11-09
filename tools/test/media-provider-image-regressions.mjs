import { spawnSync } from 'node:child_process';

// Tests use fake hosters and rolled-back local DB transactions. Require the
// final counter too: Laravel's exception renderer can otherwise exit with 0.
for (const [file, label] of [
  ['thumbnail-checks.php', 'Thumbnail integration checks'],
  ['image-upload-checks.php', 'Image upload integration checks'],
]) {
  const result = spawnSync('docker', ['compose', '-f', 'docker/compose.yml', 'exec', '-T', '-u', 'www-data', 'web', 'php', `tools/test/php/${file}`], {
    encoding: 'utf8', timeout: 180_000, maxBuffer: 3_000_000,
  });
  const summary = result.stdout?.match(new RegExp(`${label}: (\\d+) pass, (\\d+) fail`));
  if (result.status !== 0 || !summary || Number(summary[1]) === 0 || Number(summary[2]) !== 0) {
    console.error(`${file} failed or did not reach its final assertions. Raw output suppressed to protect credentials.`);
    process.exit(1);
  }
  console.log(`${label}: ${summary[1]} pass, 0 fail. No external media requests.`);
}
