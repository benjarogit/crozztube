import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { chromium, firefox, expect } from '@playwright/test';

// Real built UI and native playback with synthetic, bounded fixture bytes.
// API payloads here are controlled fixtures; PHP service/controller checks run separately.
const root=process.cwd(), manifest=JSON.parse(fs.readFileSync('build/manifest.json'));
const entry=manifest['resources/js/main.jsx'];
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'series-browser-'));
execFileSync('ffmpeg',['-v','error','-f','lavfi','-i','color=c=black:s=160x90:r=10','-t','4','-an','-c:v','libx264','-pix_fmt','yuv420p','-movflags','+faststart',path.join(dir,'video.mp4')],{stdio:'pipe'});
const media=fs.readFileSync(path.join(dir,'video.mp4'));if(media.length>65536)throw Error('Fixture exceeds bounded media budget');
const palettes=JSON.parse(execFileSync('php',['-r',"require 'app/Support/InterfacePalette.php';echo json_encode([App\\Support\\InterfacePalette::colors(),App\\Support\\InterfacePalette::colors(true)]);"],{encoding:'utf8'}));
const website={platformName:'CrozzTube',showLogoText:true,logoText:'CrozzTube',defaultThemeMode:'system',defaultPrimaryColor:'#1a73b8',defaultSecondaryColor:'#165f99',defaultPrimarySoftColor:'#e2edf7',design:{colorPaletteVersion:1,colors:palettes[0],darkColors:palettes[1],adminColors:palettes[0],adminDarkColors:palettes[1]}};
const css=new Set(entry.css||[]);const imported=e=>{for(const key of e.imports||[]){const x=manifest[key];if(x){for(const c of x.css||[])css.add(c);imported(x);}}};imported(entry);
const html=`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script>window.__PLAYHUB_WEBSITE__=${JSON.stringify(website)};window.__PLAYHUB_PLUGINS__=[];</script>${[...css].map(c=>`<link rel="stylesheet" href="/build/${c}">`).join('')}</head><body><div id="root"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
let servedRangeRequests=0;
const server=http.createServer((req,res)=>{
 const u=new URL(req.url,'http://localhost');
 if(u.pathname==='/fixture/video.mp4'){
  const headers={'Content-Type':'video/mp4','Accept-Ranges':'bytes','Cache-Control':'no-store'};
  if(req.method==='HEAD'){res.writeHead(200,{...headers,'Content-Length':media.length});res.end();return;}
  const range=req.headers.range;
  if(range){
   const match=/^bytes=(\d*)-(\d*)$/.exec(range);let start=0,end=media.length-1;
   if(!match||(!match[1]&&!match[2])){res.writeHead(416,{...headers,'Content-Range':'bytes */'+media.length});res.end();return;}
   if(match[1]){start=Number(match[1]);if(match[2])end=Math.min(Number(match[2]),end);}else start=Math.max(0,media.length-Number(match[2]));
   if(!Number.isSafeInteger(start)||!Number.isSafeInteger(end)||start<0||start>=media.length||end<start){res.writeHead(416,{...headers,'Content-Range':'bytes */'+media.length});res.end();return;}
   servedRangeRequests++;res.writeHead(206,{...headers,'Content-Length':end-start+1,'Content-Range':'bytes '+start+'-'+end+'/'+media.length});res.end(media.subarray(start,end+1));return;
  }
  res.writeHead(200,{...headers,'Content-Length':media.length});res.end(media);return;
 }
 if(u.pathname.startsWith('/build/')){const file=path.resolve(root,'.'+u.pathname);if(!file.startsWith(path.join(root,'build')+path.sep)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}res.setHeader('Content-Type',file.endsWith('.css')?'text/css':'text/javascript');res.end(fs.readFileSync(file));return;}
 res.setHeader('Content-Type','text/html');res.end(html);
});await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
// Validate the local media server before any browser check; the SDK's initial
// seek must see a valid range rather than an artificial seekableEnd of zero.
const headProbe=await fetch(origin+'/fixture/video.mp4',{method:'HEAD'});expect(headProbe.status).toBe(200);expect(headProbe.headers.get('accept-ranges')).toBe('bytes');expect((await headProbe.arrayBuffer()).byteLength).toBe(0);
const rangeProbe=await fetch(origin+'/fixture/video.mp4',{headers:{Range:'bytes=0-7'}});expect(rangeProbe.status).toBe(206);expect(rangeProbe.headers.get('content-range')).toBe('bytes 0-7/'+media.length);expect((await rangeProbe.arrayBuffer()).byteLength).toBe(8);
const invalidProbe=await fetch(origin+'/fixture/video.mp4',{headers:{Range:'bytes='+media.length+'-'}});expect(invalidProbe.status).toBe(416);expect(invalidProbe.headers.get('content-range')).toBe('bytes */'+media.length);
const browserRangeStart=servedRangeRequests;
const browserType=process.env.SERIES_BROWSER==='firefox'?firefox:chromium;
const browser=await browserType.launch({headless:true});let checks=3;
fs.mkdirSync('tools/test/.artifacts/series-seasons',{recursive:true});
const retainedLocales=['en','en_au','en_ca','en_gb','en_nz','en_za','de_de','de_at','de_ch','ca','es_ar','es_cl','es_co','es_cr','es_do','es_ec','es_es','es_gt','es_mx','es_pe','es_pr','es_uy','es_ve','fr_be','fr_ca','fr_fr','gl_es','it_it','pt_ao','pt_br','pt_pt','pt_pt_ao90','ro_ro','nl_be','nl_nl','da_dk','sv_se','nb_no','nn_no','fi','cs_cz','sk_sk','pl_pl','hr','sl_si','bs_ba','sr_rs','hu_hu','el','tr_tr','ru_ru','uk','cy','oci','si_lk','fur','lo','as'];
const fixtureCatalogs=JSON.parse(execFileSync('php',['tools/i18n/export.php'],{encoding:'utf8',maxBuffer:64*1024*1024}));
const messageKeys=['Series','Season','Episode','Next episode','Playlist changed. Reload before saving.','Choose videos from this playlist.','Set both season and episode numbers, or leave both empty.','An episode number can only appear once in each season.','Unable to load videos.'];
const referenceLocales=Object.keys(fixtureCatalogs).filter(code=>messageKeys.every(key=>typeof fixtureCatalogs[code][key]==='string'&&fixtureCatalogs[code][key].trim()));
if(retainedLocales.some(code=>!referenceLocales.includes(code)))throw Error('A previously checked locale lost a series message');
const targetedLocales=['cy','oci','as','my_mm','si_lk','fur','lo','gd'];
const targetedKeys={
 cy:messageKeys,oci:messageKeys,si_lk:messageKeys,fur:messageKeys,lo:messageKeys,
 as:messageKeys,
 gd:['Series','Episode','Next episode','Playlist changed. Reload before saving.'],
 my_mm:['Series','Episode','Next episode','Choose videos from this playlist.','Unable to load videos.'],
};
for(const code of targetedLocales)for(const key of targetedKeys[code])if(typeof fixtureCatalogs[code]?.[key]!=='string'||!fixtureCatalogs[code][key].trim())throw Error('Missing targeted-context key '+code+': '+key);
const loadingOnlyLocales=['jv_id','ps'];
for(const code of loadingOnlyLocales)if(typeof fixtureCatalogs[code]?.['Unable to load videos.']!=='string'||!fixtureCatalogs[code]['Unable to load videos.'].trim())throw Error('Missing loading-only context key '+code);
const fixtureLocales=[...new Set([...referenceLocales,...targetedLocales,...loadingOnlyLocales])];
const localeTags=JSON.parse(fs.readFileSync('resources/localization/locale-tags.json','utf8'));
const catalog=code=>{if(!fixtureLocales.includes(code))throw Error('Unknown fixture locale');return fixtureCatalogs[code];};
const direction=code=>catalog(code).__meta?.direction==='rtl'?'rtl':'ltr';
try{
 for(const dark of [false,true]) for(const owner of [true,false]){
  let fixtureAutoplay=false;let writes=[],nextCalls=0,relatedCalls=0,holdNext=null,holdVideo=null,failSeriesGet=false,failSeriesPut=false,failSeriesNext=false,localizedFailure=null,localizedValidation=null,localizedValidationKey='Set both season and episode numbers, or leave both empty.';const errors=[],nextRequests=[],videoRequests=[];
  const catalogRequests=[];
  const deferred=()=>{let release;const promise=new Promise(resolve=>{release=resolve;});return {promise,release,seen:false};};
  const finishNative=async native=>{
   await expect.poll(()=>native.evaluate(e=>Number.isFinite(e.duration)&&e.duration>0&&e.readyState>=4&&e.seekable.length>0&&e.seekable.end(e.seekable.length-1)>0)).toBe(true);
   const before=await native.evaluate(e=>({count:window.__seriesNativeEnds.length,path:location.pathname,src:e.currentSrc,surface:window.__seriesMediaSurface(e)}));
   await native.evaluate(e=>{if(e.ended)e.currentTime=0;return e.play();});
   await expect.poll(()=>native.page().evaluate(before=>window.__seriesNativeEnds.slice(before.count).some(event=>event.path===before.path&&event.src===before.src&&event.surface===before.surface),before),{message:'A genuine native ended event on the intended surface/source must be observed',timeout:8000}).toBe(true);
  };
  const context=await browser.newContext({viewport:{width:1440,height:900},reducedMotion:'reduce',colorScheme:dark?'dark':'light'});
  await context.addInitScript(({dark})=>{
   window.__seriesNativeEnds=[];window.__seriesMediaTrace=[];
   window.__seriesMediaSurface=media=>{let node=media;while(node){const parent=node.closest?.('.watch-vidstack-player,.persistent-vidstack-player');if(parent)return parent.className;node=node.getRootNode?.().host;}return null;};
   for(const name of ['play','playing','pause','seeking','seeked','timeupdate','ended','emptied','loadedmetadata','abort','progress','canplay','canplaythrough'])document.addEventListener(name,event=>{
    const media=event.composedPath()[0];if(!(media instanceof HTMLMediaElement)){if(name==='ended')window.__seriesMediaTrace.push({event:'CUSTOM_ENDED',tag:media?.tagName,cls:media?.className,path:location.pathname,trusted:event.isTrusted,trigger:event.trigger?.type,origin:event.originEvent?.type,time:media?.currentTime,duration:media?.duration});return;}
    const trace={event:name,id:media.dataset.seriesNativeId,time:media.currentTime,duration:media.duration,path:location.pathname,trusted:event.isTrusted,surface:window.__seriesMediaSurface(media),src:media.currentSrc,root:media.getRootNode().constructor.name,seekable:media.seekable.length?media.seekable.end(media.seekable.length-1):null,buffered:media.buffered.length?media.buffered.end(media.buffered.length-1):null};
    window.__seriesMediaTrace.push(trace);if(name==='ended'&&event.isTrusted)window.__seriesNativeEnds.push(trace);
   },true);
   localStorage.setItem('playhub_token','synthetic-fixture');localStorage.setItem('playhub_theme_preference',dark?'dark':'light');localStorage.setItem('playhub_language',sessionStorage.getItem('series_reference_locale')||'de_de');localStorage.setItem('playhub_autoplay_enabled','false');
  }, {dark});
  const video=(id,slug,title)=>({id,slug,title,description:'Synthetischer Inhalt',source_type:'upload',video_url:origin+'/fixture/video.mp4?video='+slug,video_sources:[{src:origin+'/fixture/video.mp4?video='+slug,type:'video/mp4'}],status:'ready',visibility:'public',duration_seconds:4,views_count:0,channel:{id:1,user_id:1,name:'Fixture',slug:'fixture'},tags:[],sale:{is_paid:false,has_access:true}});
  const videos=[video(1,'first','Eine sehr lange erste Folge für schmale Ansichten und erhaltene Eingaben'),video(3,'third','Dritte Folge'),video(9,'manual','Manuelle Warteschlange')];
  const playlist={id:1,user_id:1,title:'Synthetische Serie',privacy:'public',is_series:true,viewer_role:owner?'owner':'viewer',videos:videos.slice(0,2),video:videos[0],video_ids:[1,3],videos_count:2,members:[],channel:videos[0].channel};
  let series={playlist_id:1,is_series:true,series_revision:owner?'a'.repeat(64):null,can_manage_series:owner,episodes:videos.slice(0,2).map((v,i)=>({video_id:v.id,sort_order:i,season_number:1,episode_number:i+1,episode_title:null,available:true,video:{id:v.id,slug:v.slug,title:v.title,duration_seconds:v.duration_seconds}})),seasons:[{number:1,video_ids:[1,3]}]};
  await context.route('**/*',async route=>{
   const req=route.request(),u=new URL(req.url());if(u.origin!==origin){await route.abort();return;}
   if(!u.pathname.startsWith('/api/')){await route.continue();return;}
   const p=u.pathname;let body={};
   if(p==='/api/playlists/1/series'&&req.method()==='PUT'&&localizedValidation){await route.fulfill({status:422,json:{error:{message:'The submitted data is invalid.',validation:{episodes:[catalog(localizedValidation)[localizedValidationKey]]}}}});return;}
   if(p==='/api/playlists/1/series'&&req.method()==='PUT'&&failSeriesPut){await route.fulfill({status:409,json:{message:localizedFailure?catalog(localizedFailure)['Playlist changed. Reload before saving.']:'Controlled revision conflict'}});return;}
   if(p==='/api/playlists/1/series'&&req.method()==='GET'&&failSeriesGet){await route.fulfill({status:500,json:{message:localizedFailure?catalog(localizedFailure)['Unable to load videos.']:'Controlled series refresh failure'}});return;}
   if(p==='/api/playlists/1/series/next'&&failSeriesNext){nextCalls++;await route.fulfill({status:500,json:{message:'Controlled next failure'}});return;}
   if(p==='/api/i18n')body={languages:fixtureLocales.map(code=>({code,label:catalog(code).__meta?.label||code,direction:direction(code)})),aliases:{}};
   else if(p.startsWith('/api/i18n/')){const code=p.split('/').at(-1);catalogRequests.push(code);body={code,translations:catalog(code),direction:direction(code)};}
   else if(p==='/api/auth/me')body={user:{id:owner?1:2,name:'Synthetic viewer',username:'fixture',channel:videos[0].channel,settings:{role:'user',preferences:{autoplay:fixtureAutoplay},appearance:{accent:'platform'}}}};
   else if(p==='/api/integrations/public')body={website,features:{},auth:{registration:{enabled:true}}};
   else if(p==='/api/auth/preferences/autoplay'){fixtureAutoplay=req.postDataJSON().autoplay;body={autoplay:fixtureAutoplay,user:{id:owner?1:2,name:'Synthetic viewer',username:'fixture',channel:videos[0].channel,settings:{role:'user',preferences:{autoplay:fixtureAutoplay},appearance:{accent:'platform'}}}};}
   else if(p==='/api/playlists')body={playlists:[playlist]};
   else if(p==='/api/playlists/1')body={playlist};
   else if(p==='/api/playlists/1/series'&&req.method()==='PUT'){writes.push(req.postDataJSON());const input=req.postDataJSON();series={...series,is_series:input.is_series,episodes:series.episodes.map(e=>({...e,...input.episodes.find(x=>x.video_id===e.video_id)})),series_revision:'b'.repeat(64)};body=series;}
   else if(p==='/api/playlists/1/series')body=series;
   else if(p==='/api/playlists/1/series/next'){
    nextCalls++;const queue=u.searchParams.getAll('queue[]').map(Number);const after=Number(u.searchParams.get('after'));nextRequests.push({after,queue});
    if(holdNext){const gate=holdNext;gate.seen=true;await gate.promise;}
    const queued=queue.find(id=>id!==after&&videos.some(v=>v.id===id));const id=queued|| (after===1?3:null);const target=videos.find(v=>v.id===id);
    body={next_episode:target?{...(series.episodes.find(e=>e.video_id===id)||{sort_order:null,season_number:null,episode_number:null,episode_title:null}),video_id:id,available:true,video:{id:target.id,slug:target.slug,title:target.title,duration_seconds:target.duration_seconds},from_queue:Boolean(queued)}:null};
   }
   else if(/^\/api\/videos\/(first|third|manual)$/.test(p)){const slug=p.split('/').at(-1);videoRequests.push(slug);if(holdVideo?.slug===slug){const gate=holdVideo;gate.seen=true;await gate.promise;}body={video:videos.find(v=>v.slug===slug)};}
   else if(p==='/api/videos'){if(u.searchParams.get('sort')==='related')relatedCalls++;body={data:videos,meta:{current_page:1,last_page:1}};}
   else if(/comments/.test(p))body={comments:[],data:[]};
   else if(/chapters/.test(p))body={chapters:[]};
   else if(/time-marks/.test(p))body={time_marks:[],week:[],season:[]};
   else if(/player-ads/.test(p))body={enabled:false,ads:[]};
   await route.fulfill({json:body});
  });
  const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));
  try{
   await page.goto(origin+'/playlists?id=1');const panel=page.locator('.playlist-series-panel');await expect(panel).toBeVisible();await panel.locator('summary').click();
   if(owner){await expect(panel.getByLabel('Staffel',{exact:true}).first()).toBeVisible();const season=panel.getByLabel('Staffel',{exact:true}).first();await season.focus();await page.keyboard.press('ArrowUp');await expect(season).toHaveValue('2');await page.keyboard.press('ArrowDown');await expect(season).toHaveValue('1');checks+=2;const title=panel.locator('input[type="text"],input:not([type])').first();await title.fill('Eigene Folgenbezeichnung bleibt beim Größenwechsel erhalten');
    for(const width of [320,390,768,924,1440]){await page.setViewportSize({width,height:900});await expect(title).toHaveValue('Eigene Folgenbezeichnung bleibt beim Größenwechsel erhalten');await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);checks+=2;}
    await panel.getByRole('button',{name:'Speichern',exact:true}).click();await expect.poll(()=>writes.length).toBe(1);expect(writes[0].expected_revision).toBe('a'.repeat(64));expect(writes[0].episodes[0].episode_title).toContain('Eigene');checks+=3;
   }else{await expect(panel.locator('input')).toHaveCount(0);checks++;}
   await page.screenshot({path:`tools/test/.artifacts/series-seasons/playlist-${owner?'owner':'viewer'}-${dark?'dark':'light'}.png`,fullPage:true});
   await page.setViewportSize({width:1440,height:900});await page.goto(origin+'/watch/first?list=1');const player=page.locator('.watch-vidstack-player').first();await expect(player).toBeVisible();await expect(page.locator('.watch-sidebar')).toContainText('Nächste Folge');
   await player.evaluate(e=>e.dataset.seriesIdentity='retained');for(const width of [390,768,1440]){await page.setViewportSize({width,height:900});await expect(player).toHaveAttribute('data-series-identity','retained');checks++;}
   await finishNative(player.locator('video'));await page.waitForTimeout(400);expect(new URL(page.url()).pathname).toBe('/watch/first');expect(nextCalls).toBe(0);checks+=2;
   await expect(page.locator('.watch-sidebar')).toContainText('Dritte Folge');
   await page.screenshot({path:`tools/test/.artifacts/series-seasons/watch-${dark?'dark':'light'}.png`,fullPage:true});
   if(owner)await page.evaluate(()=>{const items=[{id:9,slug:'manual',title:'Manuelle Warteschlange'}];localStorage.setItem('playhub_playback_queue_v1',JSON.stringify(items));window.dispatchEvent(new CustomEvent('playhub:queue-updated',{detail:items}));});
   const autoplay=page.locator('.watch-sidebar .watch-autoplay-toggle');await autoplay.click();await expect(autoplay).toBeEnabled();await expect(autoplay).toHaveAttribute('aria-checked','true');
   await player.locator('video').evaluate(e=>{e.currentTime=0;return e.play();});
   await expect.poll(()=>new URL(page.url()).pathname).toBe(owner?'/watch/manual':'/watch/third');
   expect(nextCalls).toBeGreaterThan(0);if(owner)expect(new URL(page.url()).searchParams.has('list')).toBe(false);else expect(new URL(page.url()).searchParams.get('list')).toBe('1');checks+=4;
   if(owner && !dark){
    const queueValue=()=>page.evaluate(()=>JSON.parse(localStorage.getItem('playhub_playback_queue_v1')||'[]'));
    const seedQueue=async items=>page.evaluate(items=>{localStorage.setItem('playhub_playback_queue_v1',JSON.stringify(items));window.dispatchEvent(new CustomEvent('playhub:queue-updated',{detail:items}));},items);
    const resetPage=async url=>{fixtureAutoplay=false;await page.evaluate(()=>{localStorage.removeItem('playhub_playback_queue_v1');sessionStorage.removeItem('playhub_autoplay_next_slug');});await page.goto(origin+url);};
    const freshWatch=async (slug='first',items=[])=>{await resetPage('/watch/'+slug+'?list=1');await expect(page.locator('.watch-vidstack-player')).toBeVisible();await expect(page.locator('.watch-sidebar')).toContainText('Nächste Folge');await seedQueue(items);};
    const autoplayOn=async()=>{const button=page.locator('.watch-sidebar .watch-autoplay-toggle');if(await button.getAttribute('aria-checked')!=='true')await button.click();await expect(button).toHaveAttribute('aria-checked','true');};
    const watchVideo=()=>page.locator('.watch-vidstack-player video').first();
    const miniVideo=()=>page.locator('.persistent-vidstack-player video').first();
    const openMini=async()=>{const native=watchVideo();await expect.poll(()=>native.evaluate(e=>e.readyState>=4&&e.seekable.length>0&&e.seekable.end(e.seekable.length-1)>0)).toBe(true);await native.evaluate(e=>e.play());await expect.poll(()=>native.evaluate(e=>!e.paused&&e.currentTime>0)).toBe(true);await page.locator('.sidebar .nav-item').filter({hasText:/Gemeinschaft|Communit/}).first().click();await expect.poll(()=>new URL(page.url()).pathname).toBe('/communities');await expect(page.locator('.persistent-video-player:not(.is-prewarming)')).toBeVisible();await expect(miniVideo()).toBeVisible();};

    // Real Shuffle click tags the generated tail as explicit, retains shuffled
    // context across episode changes, then stops rather than wrapping the series.
    await resetPage('/playlists?id=1');
    await page.locator('.playlist-actions').getByRole('button',{name:'Mischen',exact:true}).click();
    await expect.poll(()=>new URL(page.url()).searchParams.get('list_order')).toBe('shuffle');
    const tail=await queueValue();expect(tail).toHaveLength(1);expect(tail[0].queue_origin).toBe('explicit');expect(tail[0].playlist_order).toBe('shuffle');checks+=4;
    const shuffledStart=new URL(page.url()).pathname.split('/').at(-1);await expect(page.locator('.watch-info h1')).toHaveText(videos.find(v=>v.slug===shuffledStart).title);await expect(page.locator('.watch-sidebar')).toContainText('Nächste Folge');
    await autoplayOn();await finishNative(watchVideo());
    await expect.poll(()=>new URL(page.url()).pathname).toBe('/watch/'+tail[0].slug);await expect(page.locator('.watch-info h1')).toHaveText(videos.find(v=>v.slug===tail[0].slug).title);
    expect(new URL(page.url()).searchParams.get('list_order')).toBe('shuffle');checks+=2;
    const shuffledFinal=page.url(),relatedBeforeShuffleEnd=relatedCalls;
    await finishNative(watchVideo());await page.waitForTimeout(400);
    expect(page.url()).toBe(shuffledFinal);expect(relatedCalls).toBe(relatedBeforeShuffleEnd);expect(await queueValue()).toHaveLength(0);checks+=3;

    // An explicitly queued episode from this very series must survive context
    // cleanup and be sent through the fresh queue-aware authorization endpoint.
    await freshWatch('first',[{...videos[1],playlist_id:1,queue_origin:'explicit'}]);await autoplayOn();
    const sameSeriesBefore=nextRequests.length;await finishNative(watchVideo());
    await expect.poll(()=>new URL(page.url()).pathname).toBe('/watch/third');
    expect(nextRequests.slice(sameSeriesBefore).some(r=>r.queue.includes(3))).toBe(true);expect(new URL(page.url()).searchParams.get('list')).toBe('1');checks+=3;

    // Editing the explicit queue while authorization is pending invalidates
    // that answer. Preserve both the removal and the newly added item.
    await freshWatch('first',[{...videos[2],queue_origin:'explicit'}]);await autoplayOn();
    const queueGate=deferred();holdNext=queueGate;await finishNative(watchVideo());await expect.poll(()=>queueGate.seen).toBe(true);
    await seedQueue([{...videos[1],playlist_id:1,queue_origin:'explicit'}]);
    holdNext=null;queueGate.release();await page.waitForTimeout(400);
    expect(new URL(page.url()).pathname).toBe('/watch/first');const editedQueue=await queueValue();expect(editedQueue.map(v=>v.id)).toEqual([3]);expect(editedQueue[0].queue_origin).toBe('explicit');checks+=3;

    // Hold the old next response, click another real episode link and hold its
    // video GET. Resolving the old result must not hijack the new route or eat
    // the explicit manual queue while new video state is still pending.
    await freshWatch('first',[{...videos[2],queue_origin:'explicit'}]);await autoplayOn();
    const oldNext=deferred();holdNext=oldNext;await finishNative(watchVideo());await expect.poll(()=>oldNext.seen).toBe(true);
    const newVideo=deferred();newVideo.slug='third';holdVideo=newVideo;
    await page.locator('.watch-sidebar .watch-series-episode-link').filter({hasText:'Dritte Folge'}).click();
    await expect.poll(()=>newVideo.seen).toBe(true);await expect.poll(()=>new URL(page.url()).pathname).toBe('/watch/third');
    holdNext=null;oldNext.release();await page.waitForTimeout(400);
    expect(new URL(page.url()).pathname).toBe('/watch/third');expect((await queueValue()).map(v=>v.id)).toContain(9);checks+=2;
    holdVideo=null;newVideo.release();await expect(page.locator('.watch-info h1')).toHaveText('Dritte Folge');checks++;

    // Real native playback survives leaving Watch. At end the miniplayer uses
    // the same fresh series resolver and expand keeps the playlist context.
    await freshWatch();await autoplayOn();await openMini();const beforeMiniNext=nextCalls;
    await finishNative(miniVideo());await expect(page.locator('.persistent-player-info strong')).toHaveText('Dritte Folge');
    expect(nextCalls).toBeGreaterThan(beforeMiniNext);expect(new URL(page.url()).pathname).toBe('/communities');checks+=3;
    const relatedBeforeMiniEnd=relatedCalls;await finishNative(miniVideo());await page.waitForTimeout(400);
    await expect(page.locator('.persistent-player-info strong')).toHaveText('Dritte Folge');expect(relatedCalls).toBe(relatedBeforeMiniEnd);checks+=2;
    await page.locator('.persistent-player-expand-hit').click();await expect.poll(()=>new URL(page.url()).searchParams.get('list')).toBe('1');checks++;

    await freshWatch();await openMini();const beforeOffMini=nextCalls;
    await finishNative(miniVideo());await page.waitForTimeout(400);
    expect(nextCalls).toBe(beforeOffMini);await expect(page.locator('.persistent-player-info strong')).toHaveText(videos[0].title);checks+=2;

    await freshWatch('first',[{...videos[2],queue_origin:'explicit'}]);await autoplayOn();await openMini();
    const manualMiniBefore=nextRequests.length;await finishNative(miniVideo());
    await expect(page.locator('.persistent-player-info strong')).toHaveText('Manuelle Warteschlange');
    expect(nextRequests.slice(manualMiniBefore).some(r=>r.queue.includes(9))).toBe(true);checks+=2;
    await page.locator('.persistent-player-expand-hit').click();await expect.poll(()=>new URL(page.url()).pathname).toBe('/watch/manual');expect(new URL(page.url()).searchParams.has('list')).toBe(false);checks++;

    // Save conflicts keep the in-progress owner draft until an intentional
    // refresh, instead of wiping it or hiding the recovery action.
    await resetPage('/playlists?id=1');const errorPanel=page.locator('.playlist-series-panel');await errorPanel.locator('summary').click();
    const conflictDraft=errorPanel.locator('input[type="text"],input:not([type])').first();await conflictDraft.fill('Entwurf bleibt nach Konflikt erhalten');failSeriesPut=true;
    await errorPanel.getByRole('button',{name:'Speichern',exact:true}).click();await expect(errorPanel.getByRole('alert')).toBeVisible();await expect(conflictDraft).toHaveValue('Entwurf bleibt nach Konflikt erhalten');await expect(errorPanel.getByRole('button',{name:'Aktualisieren',exact:true})).toBeEnabled();checks+=3;
    failSeriesPut=false;await errorPanel.getByRole('button',{name:'Aktualisieren',exact:true}).click();await expect(errorPanel.getByRole('alert')).toHaveCount(0);

    failSeriesGet=true;await resetPage('/watch/first?list=1');const watchError=page.locator('.watch-sidebar').getByRole('alert');await expect(watchError).toBeVisible();await expect(watchError.getByRole('button',{name:'Aktualisieren',exact:true})).toBeEnabled();checks+=2;
    failSeriesGet=false;await watchError.getByRole('button',{name:'Aktualisieren',exact:true}).click();await expect(page.locator('.watch-sidebar')).toContainText('Dritte Folge');checks++;
    await autoplayOn();failSeriesNext=true;await finishNative(watchVideo());await expect(page.locator('.watch-action-notice')).toContainText('Controlled next failure');expect(new URL(page.url()).pathname).toBe('/watch/first');checks+=2;failSeriesNext=false;
   }
   if(!owner && !dark){
    failSeriesGet=true;await page.goto(origin+'/playlists?id=1');const failedRead=page.locator('.playlist-series-panel');await expect(failedRead).toBeVisible();await failedRead.locator('summary').click();await expect(failedRead.getByRole('alert')).toBeVisible();await expect(failedRead.getByRole('button',{name:'Aktualisieren',exact:true})).toBeEnabled();checks+=3;
    failSeriesGet=false;await failedRead.getByRole('button',{name:'Aktualisieren',exact:true}).click();await expect(failedRead.locator('.playlist-series-episode-list')).toBeVisible();checks++;
   }

   if(owner)for(const locale of referenceLocales){
    const strings=catalog(locale);
    for(const key of ['Series','Season','Episode','Next episode'])if(!strings[key])throw Error('Missing series reference key '+locale+': '+key);
    await page.evaluate(code=>sessionStorage.setItem('series_reference_locale',code),locale);
    await page.goto(origin+'/playlists?id=1');
    const translated=page.locator('.playlist-series-panel');await translated.locator('summary').click();
    await expect(page.locator('html')).toHaveAttribute('lang',localeTags[locale]||locale.replace(/_/g,'-'));
    await expect(page.locator('html')).toHaveAttribute('dir',direction(locale));
    expect(await page.evaluate(()=>localStorage.getItem('playhub_language'))).toBe(locale);
    expect(catalogRequests).toContain(locale);
    await expect(translated.locator('summary')).toHaveText(strings.Series);
    await expect(translated.getByRole('spinbutton',{name:strings.Season,exact:true}).first()).toBeVisible();
    await expect(translated.getByRole('spinbutton',{name:strings.Episode,exact:true}).first()).toBeVisible();
    await expect(translated.locator('legend').first()).toHaveText(videos[0].title);
    await page.setViewportSize({width:390,height:900});
    await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    localizedFailure=locale;failSeriesPut=true;
    const draft=translated.locator('input[type="text"],input:not([type])').first();await draft.fill('Original draft: '+locale);
    await translated.locator('button[type="submit"]').click();await expect(translated.getByRole('alert')).toContainText(strings['Playlist changed. Reload before saving.']);
    await expect(draft).toHaveValue('Original draft: '+locale);failSeriesPut=false;
    localizedValidation=locale;await translated.locator('button[type="submit"]').click();
    await expect(translated.getByRole('alert')).toContainText(strings['Set both season and episode numbers, or leave both empty.']);
    await expect(translated.getByRole('alert')).not.toContainText('The submitted data is invalid.');
    await expect(draft).toHaveValue('Original draft: '+locale);localizedValidation=null;
    await page.goto(origin+'/watch/first?list=1');await expect(page.locator('.watch-sidebar')).toContainText(strings['Next episode']);
    failSeriesGet=true;await page.reload();const failure=page.locator('.watch-sidebar').getByRole('alert');
    await expect(failure).toContainText(strings['Unable to load videos.']);
    failSeriesGet=false;await failure.getByRole('button').click();await expect(page.locator('.watch-sidebar')).toContainText(strings['Next episode']);
    localizedFailure=null;checks+=17;
   }
   if(owner)for(const locale of targetedLocales){
    const strings=catalog(locale),keys=targetedKeys[locale];
    await page.evaluate(code=>sessionStorage.setItem('series_reference_locale',code),locale);
    await page.goto(origin+'/playlists?id=1');
    const targeted=page.locator('.playlist-series-panel');await targeted.locator('summary').click();
    await expect(page.locator('html')).toHaveAttribute('lang',localeTags[locale]||locale.replace(/_/g,'-'));
    expect(await page.evaluate(()=>localStorage.getItem('playhub_language'))).toBe(locale);
    expect(catalogRequests).toContain(locale);checks+=3;
    await expect(targeted.locator('summary')).toHaveText(strings.Series);
    await expect(targeted.getByRole('checkbox',{name:strings.Series,exact:true})).toBeVisible();checks+=2;
    for(const key of ['Season','Episode'])if(keys.includes(key)){
     await expect(targeted.getByRole('spinbutton',{name:strings[key],exact:true}).first()).toBeVisible();checks++;
    }
    await page.setViewportSize({width:390,height:900});
    await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);checks++;
    const draft=targeted.locator('input[type="text"],input:not([type])').first();await draft.fill('Creator title remains unchanged');
    if(keys.includes('Playlist changed. Reload before saving.')){
     localizedFailure=locale;failSeriesPut=true;await targeted.locator('button[type="submit"]').click();
     await expect(targeted.getByRole('alert')).toContainText(strings['Playlist changed. Reload before saving.']);
     await expect(draft).toHaveValue('Creator title remains unchanged');failSeriesPut=false;localizedFailure=null;checks+=2;
    }
    for(const key of ['Choose videos from this playlist.','Set both season and episode numbers, or leave both empty.','An episode number can only appear once in each season.'])if(keys.includes(key)){
     localizedValidation=locale;localizedValidationKey=key;await targeted.locator('button[type="submit"]').click();
     await expect(targeted.getByRole('alert')).toContainText(strings[key]);
     await expect(targeted.getByRole('alert')).not.toContainText('The submitted data is invalid.');
     await expect(draft).toHaveValue('Creator title remains unchanged');checks+=3;
    }
    localizedValidation=null;localizedValidationKey='Set both season and episode numbers, or leave both empty.';
    await page.screenshot({path:`tools/test/.artifacts/series-seasons/context-${locale}-${dark?'dark':'light'}-editor.png`,fullPage:true});
    if(keys.includes('Unable to load videos.')){
     localizedFailure=locale;failSeriesGet=true;await page.goto(origin+'/watch/first?list=1');
     const failure=page.locator('.watch-sidebar').getByRole('alert');
     await expect(failure).toContainText(strings['Unable to load videos.']);
     await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);checks++;
     await page.screenshot({path:`tools/test/.artifacts/series-seasons/context-${locale}-${dark?'dark':'light'}-load-error.png`,fullPage:true});
     failSeriesGet=false;await failure.getByRole('button').click();await expect(page.locator('.watch-sidebar')).toContainText(strings['Next episode']);
     localizedFailure=null;checks+=2;
    }
   }
   if(owner)for(const locale of loadingOnlyLocales){
    const strings=catalog(locale);
    await page.evaluate(code=>sessionStorage.setItem('series_reference_locale',code),locale);
    await page.setViewportSize({width:390,height:900});
    localizedFailure=locale;failSeriesGet=true;await page.goto(origin+'/watch/first?list=1');
    const failure=page.locator('.watch-sidebar').getByRole('alert');
    await expect(page.locator('html')).toHaveAttribute('lang',localeTags[locale]||locale.replace(/_/g,'-'));
    await expect(page.locator('html')).toHaveAttribute('dir',direction(locale));
    expect(await page.evaluate(()=>localStorage.getItem('playhub_language'))).toBe(locale);
    expect(catalogRequests).toContain(locale);
    await expect(failure).toContainText(strings['Unable to load videos.']);
    await expect(page.locator('.watch-info h1')).toHaveText(videos[0].title);
    await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
    await page.screenshot({path:`tools/test/.artifacts/series-seasons/context-${locale}-${dark?'dark':'light'}-load-error.png`,fullPage:true});
    failSeriesGet=false;await failure.getByRole('button').click();
    await expect(failure).toHaveCount(0);
    await expect(page.locator('.watch-sidebar')).toContainText(videos[1].title);
    localizedFailure=null;checks+=9;
    if(locale==='ps'){
     // Only the reviewed M/L keys are claimed here; the seven taxonomy and
     // numbering messages intentionally remain untranslated in this pack.
     await page.goto(origin+'/playlists?id=1');
     const partial=page.locator('.playlist-series-panel');await partial.locator('summary').click();
     const draft=partial.locator('input[type="text"],input:not([type])').first();await draft.fill('Creator title remains unchanged');
     localizedValidation=locale;localizedValidationKey='Choose videos from this playlist.';
     await partial.locator('button[type="submit"]').click();
     await expect(partial.getByRole('alert')).toContainText(strings[localizedValidationKey]);
     await expect(draft).toHaveValue('Creator title remains unchanged');
     await expect(page.locator('html')).toHaveAttribute('dir','rtl');
     await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
     await page.screenshot({path:`tools/test/.artifacts/series-seasons/context-ps-${dark?'dark':'light'}-membership.png`,fullPage:true});
     localizedValidation=null;localizedValidationKey='Set both season and episode numbers, or leave both empty.';checks+=4;
    }
   }
   expect(errors).toEqual([]);checks++;
  }catch(error){console.error(JSON.stringify({fixture:{nextCalls,nextRequests,videoRequests},page:await page.evaluate(()=>({url:location.href,media:[...document.querySelectorAll('video')].map(e=>({src:e.src,currentSrc:e.currentSrc,ready:e.readyState,network:e.networkState,error:e.error?.code,paused:e.paused,time:e.currentTime,duration:e.duration,sourceElements:[...e.querySelectorAll('source')].map(s=>({src:s.src,type:s.type})),surface:window.__seriesMediaSurface(e),html:e.outerHTML.slice(0,900)})),ends:window.__seriesNativeEnds,trace:window.__seriesMediaTrace.slice(-40),queue:JSON.parse(localStorage.getItem('playhub_playback_queue_v1')||'[]'),title:document.querySelector('.watch-info h1')?.textContent}))}));await page.screenshot({path:'tools/test/.artifacts/series-seasons/failure.png',fullPage:true});console.error(JSON.stringify(await page.locator('body *').evaluateAll(es=>es.filter(e=>e.getBoundingClientRect().right>innerWidth+2&&e.getBoundingClientRect().width>0).slice(0,18).map(e=>({tag:e.tagName,cls:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})))));throw error;}finally{holdNext?.release();holdVideo?.release();await context.close();}
 }
 console.log(`Series UI: ${checks} checks passed; one ${browserType.name()} worker, controlled API fixtures, ${media.length} synthetic video bytes, ${servedRangeRequests-browserRangeStart} browser range replies; no application database or provider traffic.`);
}finally{await browser.close();await new Promise(r=>server.close(r));fs.rmSync(dir,{recursive:true,force:true});}
