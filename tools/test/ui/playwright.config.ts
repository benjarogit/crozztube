import { defineConfig } from '@playwright/test';

const baseURL = process.env.BASE_URL || 'https://crozztube.local:8443';

export default defineConfig({
  testDir: './specs',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    viewport: { width: 1440, height: 900 },
  },
  outputDir: 'test-results',
  projects: [
    {
      name: 'chromium',
      testIgnore: /responsive-cross\.spec\.ts/,
      use: { browserName: 'chromium' },
    },
    {
      name: 'firefox-responsive',
      testMatch: /responsive-cross\.spec\.ts/,
      use: { browserName: 'firefox', trace: 'off', video: 'off' },
    },
    {
      name: 'webkit-responsive',
      testMatch: /responsive-cross\.spec\.ts/,
      use: {
        browserName: 'webkit', trace: 'off', video: 'off',
        ...((process.env.PW_WEBKIT_WS_ENDPOINT || process.env.PW_WEBKIT_WS) ? {
          connectOptions: { wsEndpoint: process.env.PW_WEBKIT_WS_ENDPOINT || process.env.PW_WEBKIT_WS! },
        } : {}),
      },
    },
  ],
});
