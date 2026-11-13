import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium, firefox, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const video = applicationJson('/api/videos?type=video&per_page=1', false).data[0];
const artifacts = 'tools/test/.artifacts/viewer-quality';
fs.mkdirSync(artifacts, { recursive: true });
const plugin = fs.readFileSync('docker/plugin-src/crozztube.source-resolver/assets/watch-enhancer.js', 'utf8');
const expectContained = async (page, label) => expect.poll(() => layoutProblems(page), { message: label, timeout: 5000 }).toEqual([]);
const browserType = process.env.QUALITY_BROWSER === 'firefox' ? firefox : chromium;
let trustProfile, persistent;
if (browserType === firefox) {
  trustProfile = fs.mkdtempSync(path.join(os.tmpdir(), 'crozztube-quality-trust-'));
  const caRoot = execFileSync('mkcert', ['-CAROOT'], {encoding:'utf8'}).trim();
  execFileSync('certutil', ['-N', '--empty-password', '-d', 'sql:'+trustProfile]);
  execFileSync('certutil', ['-A', '-d', 'sql:'+trustProfile, '-n', 'CrozzTube local development CA', '-t', 'C,,', '-i', path.join(caRoot,'rootCA.pem')]);
  persistent = await firefox.launchPersistentContext(trustProfile, {headless:true,ignoreHTTPSErrors:false});
}
const browser = persistent ? persistent.browser() : await browserType.launch({headless:true});
let checks = 0;
const assert = (condition, label) => { expect(condition, label).toBe(true); checks++; };
const ratio = (fg, bg) => {
  const lum = c => { let v=c.match(/[\d.]+/g).slice(0,3).map(Number); if (!c.startsWith('color(srgb')) v=v.map(x=>x/255); v=v.map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4); return v[0]*.2126+v[1]*.7152+v[2]*.0722; };
  const a=lum(fg),b=lum(bg);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
};
try {
  for (const dark of [false, true]) for (const role of ['guest','viewer','owner']) {
    const authenticated = role !== 'guest';
    const session = await layoutSession(browser, { authenticated, dark, width: 1440, strictHttps: true,
      transform: (path, data) => role === 'viewer' && path.startsWith('/api/auth/me') ? {...data,user:{...data.user,settings:{...data.user.settings,role:'user',account_role:'user',admin:false,is_admin:false}}} : path.startsWith('/api/continue-watching') ? { ...data, data: [{...video, progress:40, duration_seconds:600, progress_seconds:240}] } : data.video ? { ...data, video: { ...data.video, title: 'Ein sehr langer Videotitel zum Prüfen der responsiven Oberfläche und unveränderter Inhalte', source_type:'upload', video_url:'/fixture/menu.mp4', video_sources:[{src:'/fixture/menu.mp4',type:'video/mp4'}], embed_url:null, playback_pending:false,
        channel: { ...data.video.channel, user_id: role === 'owner' ? 1 : 999999 } } } : path.includes('/viewer-state') ? {...data, can_report: role === 'viewer'} : data });
    const { page, context } = session; page.setDefaultTimeout(12000);
    let currentVote=0, statusRequests=0;
    const marks = () => [{ id:990001,start_seconds:45,body:'Eine öffentliche Zeitmarke mit einer langen Notiz, die auf kleinen Geräten vollständig lesbar bleiben muss.',visibility:'public',owned:role==='owner',likes_count:3+(currentVote===1?1:0),dislikes_count:currentVote===-1?1:0,viewer_vote:currentVote,score:3+currentVote }, ...(authenticated ? [{id:990002,start_seconds:66,body:'Meine geheime private Notiz',visibility:'private',owned:true}] : [])];
    await context.route('**/plugin-assets/crozztube.source-resolver?**', async route => {
      if(new URL(route.request().url()).searchParams.get('asset')==='assets/watch-enhancer.js') await route.fulfill({contentType:'text/javascript',body:plugin});else await route.fallback();
    });
    await context.route('**/api/source-resolver/status/**', route => { statusRequests++; return route.fulfill({json:{source:{state:'ready',fresh:true,has_direct:true,refreshing:false,auto_refresh_allowed:false,mirror:{mode:'iframe',embed_url:'https://www.youtube.com/embed/dQw4w9WgXcQ',label:'YouTube',provider:'youtube'}}}}); });
    await context.route('**/api/**/time-marks**', route => route.fulfill({json:route.request().url().includes('/rank')?{week:marks().filter(m=>m.visibility==='public'),season:marks().filter(m=>m.visibility==='public')}:{time_marks:marks().filter(m=>route.request().url().endsWith('/mine')||m.visibility==='public')}}));
    await context.route('**/api/time-marks/990001/vote', async route => { currentVote=route.request().postDataJSON().value; await route.fulfill({json:{vote:{value:currentVote}}}); });
    await context.addInitScript(() => {globalThis.qualityCopies=[];Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>globalThis.qualityCopies.push(text)}});});
    try {
      await session.goto(`/watch/${video.slug}`);
      await expect.poll(()=>statusRequests).toBeGreaterThan(0);checks++;
      const player=page.locator('.watch-vidstack-player');await expect(player).toBeVisible();await player.evaluate(e=>e.dataset.qualityPlayer='preserved');
      const draft=page.locator('.watch-comments-card .comment-composer-field > input').first();if(authenticated) await draft.fill('Unveröffentlichter Kommentar bleibt erhalten');
      const marksSection=page.locator('.watch-time-marks');await expect(marksSection).toContainText('öffentliche Zeitmarke');
      if(role==='viewer') {
        const like=marksSection.getByRole('button',{name:'Like time mark',exact:true}),dislike=marksSection.getByRole('button',{name:'Dislike time mark',exact:true});
        await like.click();await expect(like).toHaveAttribute('aria-pressed','true');
        await dislike.click();await expect(dislike).toHaveAttribute('aria-pressed','true');await expect(like).toHaveAttribute('aria-pressed','false');
        await dislike.click();await expect(dislike).toHaveAttribute('aria-pressed','false');checks+=4;
        await marksSection.locator('li').filter({hasText:'Meine geheime private Notiz'}).getByRole('button',{name:'Copy time link',exact:true}).click();
        const copied=await page.evaluate(()=>globalThis.qualityCopies.at(-1));assert(copied.includes('t=66')&&!copied.includes('geheime'),'private sharing includes only position');
      }
      if(role==='owner') {await expect(marksSection.getByRole('button',{name:'Like time mark',exact:true})).toHaveCount(0);await expect(marksSection).toContainText('Your time mark');checks+=2;}
      if(role==='guest') {await expect(marksSection).not.toContainText('geheime');checks++;}
      for(const width of [320,390,768,924,1440,1915]) {
        await page.setViewportSize({width,height:902});await expectContained(page,`${role} ${dark} ${width}`);checks++;
        const more=page.locator('.watch-toolbar').getByRole('button',{name:'Video actions',exact:true});await more.click();
        await expect(page.getByRole('menuitemcheckbox',{name:'Mirror',exact:true})).toBeVisible();checks++; if(role === 'viewer') {await expect(page.getByRole('menuitem',{name:'Manage video',exact:true})).toHaveCount(0);checks++;}
        await expect(page.locator('.watch-toolbar .crz-mirror-toggle')).toHaveCount(0);checks++;
        await page.keyboard.press('Escape');await expect(more).toBeFocused();checks++;
        await expect(player).toHaveAttribute('data-quality-player','preserved');checks++;
        if(authenticated){await expect(draft).toHaveValue('Unveröffentlichter Kommentar bleibt erhalten');checks++;}
        if(width<=390){const ys=await page.locator('.action-bar > button,.action-bar > .video-detail-menu-shell').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().height).map(e=>Math.round(e.getBoundingClientRect().y)));assert(new Set(ys).size===2,'mobile toolbar has two rows'); const widths=await page.locator('.action-bar > button,.action-bar > .video-detail-menu-shell > button').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().height).map(e=>Math.round(e.getBoundingClientRect().width))); assert(Math.max(...widths)-Math.min(...widths)<=1,'mobile actions share a width');}
      }
      await page.setViewportSize({width:390,height:844});
      await page.screenshot({path:`${artifacts}/watch-${role}-${dark?'dark':'light'}.png`,fullPage:true});
      if(authenticated){await page.getByRole('button',{name:'Create a clip',exact:true}).click();await expect(page.getByRole('dialog')).toContainText('Anyone with its Odysee link can still watch it.');await expectContained(page,'clip dialog');checks+=2;await page.getByRole('dialog').getByRole('button',{name:'Close',exact:true}).first().click();}
      for (const path of ["/","/explore","/search?type=channels","/search?type=community"]) { await session.goto(path); for (const w of [320,768,1440]) { await page.setViewportSize({width:w,height:902}); await expectContained(page, path+" "+role+" "+w); checks++; } }
      assert(session.errors.length===0,session.errors.join('\n')); assert(session.failedGets.length===0,'no API 500s');assert(session.writes.length===0,session.writes.join('\n'));
      if(role==='owner') {
        await page.setViewportSize({width:1440,height:900});await session.goto('/admin/roles-permissions');
        const tab=page.locator('.admin-tabs button.active').first();await expect(tab).toBeVisible();
        const colors=await tab.evaluate(e=>({fg:getComputedStyle(e).color,bg:getComputedStyle(e.closest('.admin-card')).backgroundColor}));assert(ratio(colors.fg,colors.bg)>=4.5,'admin tab text contrast');
        await session.goto('/'); const card = page.locator('.continue-card').first(); await expect(card).toBeVisible(); const cardSummary = card.locator('summary'); await cardSummary.click(); await expect(card.getByRole('button',{name:'Remove',exact:true})).toBeVisible(); await page.keyboard.press('Escape'); await expect(cardSummary).toBeFocused(); await expect(card.locator('details')).not.toHaveAttribute('open',''); checks+=4;
        await session.goto('/upload');await expect(page.getByRole('textbox',{name:/^Title/})).toBeVisible();await expect(page.getByRole('textbox',{name:/^Description/})).toBeVisible();checks+=2;
        await session.goto('/communities');await expect(page.locator('.communities-sort')).not.toHaveText(/Latest.*Latest/);await expect(page.locator('.community-post-actions button').first()).toHaveAccessibleName(/post/);checks+=2;
        await expectContained(page,'communities');checks++;
      }
    } finally { await context.close(); }
  }
  for (const dark of [false, true]) {
    const session = await layoutSession(browser, {language:'de_de',dark,width:390,strictHttps:true, transform: (p,d) => d.video ? {...d,video:{...d.video,source_type:'upload',video_url:'/fixture/menu.mp4',video_sources:[{src:'/fixture/menu.mp4',type:'video/mp4'}],embed_url:null,playback_pending:false}} : d});
    try {
      await session.context.route('**/api/source-resolver/status/**',r=>r.fulfill({json:{source:{state:'unavailable',fresh:false,refreshing:false,auto_refresh_allowed:false,mirror:null}}}));
      await session.context.route('**/api/**/time-marks**',r=>r.fulfill({json:{time_marks:[{id:990003,body:'Eine lange deutsche öffentliche Zeitmarke',start_seconds:12,visibility:'public',owned:false,viewer_vote:0,likes_count:2,dislikes_count:0}],week:[],season:[]}}));
      await session.goto('/watch/'+video.slug);
      await expect(session.page.getByRole('button',{name:'Zeitmarke gefällt mir',exact:true})).toBeVisible();
      await expect(session.page.getByRole('button',{name:'Zeitmarke gefällt mir nicht',exact:true})).toBeVisible();
      await expectContained(session.page,'German watch'); checks+=3;
      await session.page.getByRole('button',{name:'Clip erstellen',exact:true}).click();
      await expect(session.page.getByRole('dialog')).toContainText('Wer den Odysee-Link hat, kann ihn trotzdem ansehen.');
      await expectContained(session.page,'German clip'); checks+=2;
      await session.page.screenshot({path:artifacts+'/clip-de-'+(dark?'dark':'light')+'.png',fullPage:true});
      assert(session.errors.length===0,'German rendering errors');
    } finally {await session.context.close();}
  }
  console.log(`Viewer quality: ${checks} checks passed. ${browserType.name()}, one worker, read-only identities and controlled vote fixtures; no media downloads or persistent user changes.`);
} finally {await browser.close();if(trustProfile)fs.rmSync(trustProfile,{recursive:true,force:true});}
