import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import type { Page, TestInfo } from '@playwright/test';

/** Keep a small selected set of passing evidence, rather than 400+ screenshots. */
export async function retainScreenshot(page: Page, info: TestInfo, name: string) {
  const directory = join(process.env.ARTIFACT_DIR || '/tmp/crozztube-tests', 'responsive-screenshots');
  await mkdir(directory, { recursive: true });
  const file = `${info.project.name}-${name}`.replace(/[^a-z0-9-]/gi, '-') + '.png';
  const path = join(directory, file);
  await page.screenshot({ path, fullPage: false });
  await info.attach(name, { path, contentType: 'image/png' });
}
