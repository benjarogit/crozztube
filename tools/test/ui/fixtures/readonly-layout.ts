import { execFileSync } from 'node:child_process';
import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * Real application GET responses without creating tokens or changing live data.
 * Identity exists only in the PHP process; database writes made by GET handlers
 * are rolled back, queues are faked, and cache state is process-local. Response
 * bodies and credentials are never persisted in fixture files or test traces.
 */
const readOnlyPhp = String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['cache.default' => 'array']);
Illuminate\Support\Facades\Bus::fake();
Illuminate\Support\Facades\Queue::fake();
$request = Illuminate\Http\Request::create($argv[1], 'GET');
$request->headers->set('Accept', 'application/json');
$request->headers->set('X-PlayHub-Language', $argv[3]);
$app->instance('request', $request);
if ($argv[2] !== '0') {
    Illuminate\Support\Facades\Auth::guard('sanctum')->setUser(App\Models\User::findOrFail((int) $argv[2]));
}
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $response = $kernel->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}`;

type Payload = { status: number; body: string };
type PayloadTransform = (path: string, payload: any) => any;
const payloads = new Map<string, Payload>();

export function applicationGet(path: string, authenticated = true, language = 'en'): Payload {
  if (!path.startsWith('/api/') || /[\r\n]/.test(path)) throw new Error('Layout fixtures accept local API GET paths only.');
  const user = authenticated ? process.env.LAYOUT_USER_ID || '1' : '0';
  const key = `${user}:${language}:${path}`;
  if (!payloads.has(key)) {
    const output = execFileSync('docker', [
      'exec', process.env.WEB_CONTAINER || 'crozztube-web', 'php', '-r', readOnlyPhp, path, user, language,
    ], { encoding: 'utf8', timeout: 20_000, maxBuffer: 20_000_000, stdio: ['ignore', 'pipe', 'pipe'] });
    payloads.set(key, JSON.parse(output));
  }
  return payloads.get(key)!;
}

export function applicationJson(path: string, authenticated = true): any {
  const response = applicationGet(path, authenticated);
  if (response.status !== 200) throw new Error(`Layout fixture GET ${path} returned ${response.status}.`);
  return JSON.parse(response.body);
}

export type LayoutOptions = {
  authenticated?: boolean;
  width?: number;
  height?: number;
  dark?: boolean;
  language?: string;
  touch?: boolean;
  transform?: PayloadTransform;
};

export type LayoutSession = {
  context: BrowserContext;
  page: Page;
  writes: string[];
  failedGets: string[];
  errors: string[];
  goto: (path: string) => Promise<void>;
};

export async function layoutSession(browser: Browser, options: LayoutOptions = {}): Promise<LayoutSession> {
  const authenticated = options.authenticated !== false;
  const language = options.language || 'en';
  const base = process.env.BASE_URL || 'https://crozztube.local:8443';
  const origin = new URL(base).origin;
  const context = await browser.newContext({
    baseURL: base, ignoreHTTPSErrors: true,
    viewport: { width: options.width || 1440, height: options.height || 900 },
    reducedMotion: 'reduce', colorScheme: options.dark ? 'dark' : 'light',
    locale: language === 'de' ? 'de-DE' : 'en-US',
    ...(options.touch ? { hasTouch: true, isMobile: true } : {}),
  });
  await context.addInitScript(({ authenticated, dark, language }) => {
    if (authenticated) localStorage.setItem('playhub_token', 'layout-fixture-in-memory-identity');
    else localStorage.removeItem('playhub_token');
    localStorage.setItem('playhub_autoplay_enabled', 'false');
    localStorage.setItem('playhub_theme_preference', dark ? 'dark' : 'light');
    localStorage.setItem('playhub_theme', dark ? 'dark' : 'light');
    localStorage.setItem('playhub_language', language);
  }, { authenticated, dark: options.dark === true, language });

  const writes: string[] = [], failedGets: string[] = [], errors: string[] = [];
  await context.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    if (!['GET', 'HEAD'].includes(request.method())) {
      writes.push(`${request.method()} ${url.pathname}`);
      await route.fulfill({ status: 403, contentType: 'application/json', body: '{"message":"Read-only layout test"}' });
      return;
    }
    // Layout tests never download streams, ads, remote embeds or thumbnails.
    // Playback is checked separately with the real source/player tests.
    if (url.origin !== origin || request.resourceType() === 'media'
      || /\/source-resolver\/(manifest|playlist|seg|download)(\/|\?|$)/.test(url.pathname)
      || /\.(m3u8|mp4|webm|m4s|ts|aac)(?:$|\?)/i.test(url.pathname)) {
      await route.abort('blockedbyclient');
      return;
    }
    if (url.pathname.startsWith('/api/')) {
      const path = url.pathname + url.search;
      const response = applicationGet(path, authenticated, language);
      if (response.status >= 500) failedGets.push(`${path}: ${response.status}`);
      let body = response.body;
      if (options.transform && response.status === 200) {
        body = JSON.stringify(options.transform(path, JSON.parse(body)));
      }
      await route.fulfill({ status: response.status, contentType: 'application/json', body });
      return;
    }
    await route.continue();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  const goto = async (path: string) => {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toHaveText('');
  };
  return { context, page, writes, failedGets, errors, goto };
}

/** Local horizontal scrollers are allowed; clipping the complete page is not. */
export async function layoutProblems(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const issues: string[] = [];
    if (document.documentElement.scrollWidth > innerWidth + 1) {
      issues.push(`document width ${document.documentElement.scrollWidth} exceeds viewport ${innerWidth}`);
    }
    const content = document.querySelector('.admin-main') || document.querySelector('.app-main') || document.body;
    const allowedScroller = (element: Element): boolean => {
      for (let parent = element.parentElement; parent && parent !== content; parent = parent.parentElement) {
        const style = getComputedStyle(parent);
        if (['auto', 'scroll'].includes(style.overflowX) && parent.scrollWidth > parent.clientWidth + 2) return true;
      }
      return false;
    };
    for (const element of content.querySelectorAll<HTMLElement>('*')) {
      const style = getComputedStyle(element), rect = element.getBoundingClientRect();
      if (!element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) || rect.width < 4 || rect.height < 4) continue;
      if (element.closest('.sidebar,.admin-sidebar,[aria-hidden="true"],.sr-only,.visually-hidden')) continue;
      // Only media masks/crops may deliberately extend inside a clipped stage.
      if (element.closest('.thumb,.avatar,.video-player,.shorts-player,.shorts-detail-player-card,.persistent-video-player')) continue;
      if ((rect.left < -2 || rect.right > innerWidth + 2) && !allowedScroller(element)) {
        issues.push(`${element.tagName.toLowerCase()}.${String(element.className).replace(/\s+/g, '.').slice(0,90)} bounds ${Math.round(rect.left)}..${Math.round(rect.right)}`);
      }
      if (issues.length >= 8) break;
    }
    // The navigation lives outside app-main. Inspect its visible contents too;
    // only a genuinely closed/offscreen menu is exempt, never an open footer.
    for (const sidebar of document.querySelectorAll<HTMLElement>('.sidebar,.admin-sidebar')) {
      const bounds = sidebar.getBoundingClientRect();
      if (sidebar.hasAttribute('inert') || sidebar.getAttribute('aria-hidden') === 'true'
        || !sidebar.checkVisibility({ checkVisibilityCSS: true }) || bounds.right <= 0 || bounds.left >= innerWidth) continue;
      for (const element of sidebar.querySelectorAll<HTMLElement>('*')) {
        const rect = element.getBoundingClientRect();
        if (!element.checkVisibility({ checkVisibilityCSS: true }) || rect.width < 4 || rect.height < 4
          || element.closest('[aria-hidden="true"],.sr-only,.visually-hidden')) continue;
        if (rect.left < bounds.left - 2 || rect.right > bounds.right + 2) {
          issues.push(`visible sidebar ${element.tagName.toLowerCase()}.${String(element.className).slice(0,70)} bounds ${Math.round(rect.left)}..${Math.round(rect.right)}`);
        }
        if (issues.length >= 8) break;
      }
    }
    return issues;
  });
}

export async function expectContained(page: Page, label = ''): Promise<void> {
  expect(await layoutProblems(page), label).toEqual([]);
}
