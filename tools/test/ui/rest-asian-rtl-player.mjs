import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-asian-rtl-player-controls.json')));
const packs=JSON.parse(execFileSync('docker',['exec','crozztube-web','php','tools/i18n/export.php'],{encoding:'utf8',maxBuffer:100*1024*1024}));
const slug=applicationJson('/api/videos?per_page=50',false).data.find(v=>!v.is_short).slug;
const temporary=fs.mkdtempSync('/tmp/rest-asian-player-');
const mediaFile=path.join(temporary,'fixture.mp4');
execFileSync('ffmpeg',['-hide_banner','-loglevel','error','-f','lavfi','-i','color=c=black:s=160x90:r=5','-f','lavfi','-i','anullsrc=r=44100:cl=mono','-t','20','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p','-c:a','aac','-movflags','+faststart','-y',mediaFile],{maxBuffer:1024*1024});
const media=fs.readFileSync(mediaFile);fs.rmSync(temporary,{recursive:true,force:true});
const hash=x=>createHash('sha256').update(String(x)).digest('hex');
const requested=process.argv.find(x=>x.startsWith('--locale='))?.slice(9);
const browser=await chromium.launch({headless:true});
try{for(const locale of Object.keys(batch.messages.Play).filter(x=>!requested||x===requested)){
const observed=new Set();const word=k=>{const v=packs[locale][k];assert.ok(v);observed.add(k);return v;};
const session=await layoutSession(browser,{authenticated:false,language:locale,width:390,transform:(url,payload)=>url===`/api/videos/${slug}`?{...payload,player_ads:null,video:{...payload.video,title:'Saved Home',description:'Settings Home',source_type:'upload',import_provider:null,embed_url:null,playback_pending:false,video_url:'/rest-asian-fixture.mp4',video_sources:[{src:'/rest-asian-fixture.mp4',type:'video/mp4'}],renditions:[],duration_seconds:20,is_short:false,chapters_enabled:true,chapters:[{start_time:0,title:'Home'},{start_time:10,title:'Saved'}],captions_url:null,caption_tracks:[{url:'/rest-asian-fixture.vtt',language:'en',label:'Home',format:'webvtt',default:false}]}}:payload});
try{const page=session.page;page.setDefaultTimeout(10000);
await session.context.route('**/rest-asian-fixture.mp4',route=>route.fulfill({contentType:'video/mp4',body:media}));
await session.context.route('**/rest-asian-fixture.vtt',route=>route.fulfill({contentType:'text/vtt',body:'WEBVTT\n\n00:00.000 --> 00:20.000\nSaved Home\n'}));
await session.goto(`/watch/${slug}`);
const player=page.locator('.watch-vidstack-player');await expect(player).toBeVisible();
await expect(player.locator('video')).toHaveJSProperty('readyState',4);
await player.hover();
if(process.argv.includes('--inspect'))console.log(await player.ariaSnapshot());
await expect(player.locator('.vds-play-button').first()).toHaveAttribute('aria-label',word('Play'));
await expect(player.locator('.vds-mute-button').first()).toHaveAttribute('aria-label',word('Mute'));

await expect(page.getByRole('heading',{name:'Saved Home',exact:true})).toBeVisible();
const playToggle=player.getByRole('button',{name:word('Play'),exact:true});
await playToggle.click();
await expect(playToggle).toHaveAttribute('aria-pressed','true');
await playToggle.hover();
await expect(page.getByRole('tooltip',{name:word('Pause'),exact:true})).toBeVisible();
await playToggle.click();
await expect(playToggle).toHaveAttribute('aria-pressed','false');
await playToggle.hover();
await expect(page.getByRole('tooltip',{name:word('Play'),exact:true})).toBeVisible();
// Settings/captions are opened locally; no account/engagement mutation is sent.
const settings=player.getByRole('button',{name:word('Settings'),exact:true});
await settings.click();
if(process.argv.includes('--inspect'))console.log(await page.getByRole('menu').ariaSnapshot());
const settingsMenu=page.getByRole('menu');
for(const key of ['Speed','Accessibility','Audio','Captions']) await expect(settingsMenu.getByRole('menuitem').filter({hasText:word(key)})).toBeVisible();
await settingsMenu.getByRole('menuitem').filter({hasText:word('Captions')}).click();
if(process.argv.includes('--inspect'))console.log(await page.getByRole('menu').ariaSnapshot());
await expect(page.getByRole('menuitemradio',{name:'Home',exact:true})).toBeVisible();
await page.getByRole('menuitemradio',{name:'Home',exact:true}).click();
await expect(player.locator('.vds-captions')).toContainText('Saved Home');
await page.keyboard.press('Escape');
await player.getByRole('button',{name:word('Chapters'),exact:true}).click();
if(process.argv.includes('--inspect'))console.log(await page.getByRole('menu').ariaSnapshot());
await expect(page.getByRole('menuitemradio').filter({hasText:'Home'})).toBeVisible();
if(process.argv.includes('--inspect'))console.log(JSON.stringify(await player.evaluate(el=>{
let f=el[Object.keys(el).find(k=>k.startsWith('__reactFiber'))];
while(f){for(const ref of [f.ref,f.memoizedProps?.ref]){const p=ref?.current;if(p?.textTracks)return {duration:p.duration,tracks:Array.from(p.textTracks).map(t=>({kind:t.kind,label:t.label,mode:t.mode,cues:Array.from(t.cues||[]).map(c=>({text:c.text,start:c.startTime,end:c.endTime}))}))};}f=f.return;}
return {diagnostic:'no player ref in host ancestry'};
}),null,2));

await page.keyboard.press('Escape');
await expect(player.getByRole('button',{name:word('Fullscreen'),exact:true})).toBeVisible();
assert.deepEqual(await layoutProblems(page),[],`${locale}: narrow player/menu`);
assert.deepEqual(session.errors,[],`${locale}: runtime errors`);
assert.deepEqual(session.writes,[],`${locale}: no mutations`);
if(process.argv.includes('--record')){
const f=path.join(root,'tools/i18n/reviews',locale+'.json'),r=JSON.parse(fs.readFileSync(f));
for(const k of observed){const row=r.messages[hash(k)];assert.equal(row?.source_hash,hash(packs.en[k]??k));assert.equal(row?.translation_hash,hash(packs[locale][k]));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Actual native Watch player at390px with local synthetic MP4/VTT: Play pressed-state and translated Play/Pause tooltips; settings speed/accessibility/audio/captions; literal track name Home and caption Saved Home; first chapter title Home; fullscreen control; no external media, writes/runtime errors/overflow. Does not certify every chapter or every player setting.'])];}
fs.writeFileSync(f,JSON.stringify(r,null,2)+'\n');}
console.log(`${locale}: verified ${observed.size} player labels; real local media, literal caption/track/first chapter, no writes.`);
}finally{await session.context.close();}
}}finally{await browser.close();}
