import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {applicationJson,layoutSession,layoutProblems} from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batches=['rest-romance-player-controls','rest-romance-watch-actions','rest-romance-player-font-options'].map(name=>JSON.parse(fs.readFileSync(path.join(root,`tools/i18n/batches/${name}.json`))));
const messages=Object.assign({},...batches.map(b=>b.messages));
const reference=applicationJson('/api/i18n/en',false).translations;
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const items=applicationJson('/api/videos?per_page=50',false).data||[],slug=items.find(v=>!v.is_short)?.slug;
assert.ok(slug,'One public video fixture is required.');
const locales=['fr_fr','fr_ca','es_es','es_ar','es_mx','pt_pt','pt_pt_ao90','pt_br','it_it','ca','gl_es','eo'];
const media=(()=>{const file='/tmp/rest-romance-player-'+process.pid+'.mp4';try{execFileSync('docker',['exec','crozztube-web','ffmpeg','-hide_banner','-loglevel','error','-f','lavfi','-i','color=c=black:s=320x180:r=10','-t','30','-c:v','libx264','-pix_fmt','yuv420p','-an','-movflags','+faststart','-y',file]);return execFileSync('docker',['exec','crozztube-web','cat',file]);}finally{execFileSync('docker',['exec','crozztube-web','rm','-f',file]);}})();
const selected=process.argv.find(v=>v.startsWith('--locale='))?.split('=')[1];
const browser=await chromium.launch({headless:true});
try{for(const locale of selected?[selected]:locales){
 const value=key=>messages[key]?.[locale]??messages[key]?.[locale.split('_')[0]];
 const session=await layoutSession(browser,{authenticated:false,language:locale,width:1100,transform:(url,payload)=>url===`/api/videos/${slug}`?{...payload,player_ads:null,video:{...payload.video,source_type:'upload',import_provider:null,title:'Home',description:'Saved',thumbnail_url:null,video_url:'/fixtures/native.mp4',video_sources:[{src:'/fixtures/native.mp4',type:'video/mp4'}],embed_url:null,playback_pending:false,duration_seconds:30,chapters_enabled:true,chapters:[{start_time:0,title:'Home'},{start_time:5,title:'Settings'}],caption_tracks:[{url:'/fixtures/subtitles.vtt',format:'vtt',label:'Saved',language:'en',default:true}]}}:payload});
 await session.context.route('**/fixtures/native.mp4',route=>{const range=route.request().headers()['range']?.match(/bytes=(\d+)-(\d*)/);const start=range?Number(range[1]):0,end=range&&range[2]?Math.min(Number(range[2]),media.length-1):media.length-1;return route.fulfill({status:range?206:200,contentType:'video/mp4',headers:{'Accept-Ranges':'bytes',...(range?{'Content-Range':`bytes ${start}-${end}/${media.length}`}:{})},body:media.subarray(start,end+1)});});
 await session.context.route('**/fixtures/subtitles.vtt',route=>route.fulfill({status:200,contentType:'text/vtt',body:'WEBVTT\n\n00:00:00.000 --> 00:00:30.000\nHome\n'}));
 session.page.setDefaultTimeout(8000);
 const observed=new Set();
 try{
  await session.goto(`/watch/${slug}`);const player=session.page.locator('.watch-vidstack-player');await expect(player).toBeVisible();
  for(const[selector,key]of [['.vds-play-button','Play'],['.vds-mute-button','Mute'],['.vds-caption-button','Captions'],['.vds-pip-button','PiP'],['.vds-fullscreen-button','Fullscreen'],['.watch-theater-button','Enter theater mode']]){
   await expect(player.locator(selector).first()).toHaveAttribute('aria-label',value(key));observed.add(key);
  }
  for(const key of ['Settings','Chapters']){await expect(player.getByRole('button',{name:value(key),exact:true})).toHaveCount(1);observed.add(key);}
  for(const[selector,key]of [['.vds-airplay-button','AirPlay'],['.vds-google-cast-button','Google Cast']]){
   await expect(player.locator(selector).first()).toHaveAttribute('aria-label',`${value(key)} ${value('Disconnected')}`);observed.add(key);observed.add('Disconnected');
  }
  await player.hover();
  await expect.poll(()=>player.evaluate(el=>el.querySelector('video')?.readyState||0)).toBeGreaterThan(1);
  const overlay=session.page.locator('.watch-poster-overlay');
  if(await overlay.count())await overlay.click();else await player.locator('.vds-play-button').click();
  // Installed @vidstack/react DefaultPlayButton deliberately keeps playText as aria-label; state is aria-pressed and tooltip switches to pauseText.
  const play=player.locator('.vds-play-button');
  await expect(play).toHaveAttribute('aria-label',value('Play'));
  await expect(play).toHaveAttribute('aria-pressed','true');
  await play.hover();
  const tooltipId=await play.getAttribute('data-describedby');assert.ok(tooltipId);
  await expect(session.page.locator(`[id="${tooltipId}"]`)).toHaveText(value('Pause'));observed.add('Pause');
  await player.hover();await player.locator('.vds-play-button').click();
  await expect(player.locator('.vds-play-button')).toHaveAttribute('aria-label',value('Play'));
  await expect(player.locator('.vds-play-button')).toHaveAttribute('aria-pressed','false');
  await player.hover();await player.getByRole('button',{name:value('Chapters'),exact:true}).click();
  await expect(session.page.getByRole('menu').getByText('Home',{exact:true})).toBeVisible();
  await expect(session.page.getByRole('menu').getByText('Settings',{exact:true})).toBeVisible();
  await session.page.keyboard.press('Escape');
  await player.hover();await player.getByRole('button',{name:value('Settings'),exact:true}).click();
  await expect(session.page.getByRole('menu')).toContainText('Saved');
  for(const key of ['Speed','Accessibility','Caption Styles','Audio','Boost','Captions']){await expect(session.page.getByRole('menu')).toContainText(value(key));observed.add(key);}
  if(process.argv.includes('--font-options')){
  await session.page.getByText(value('Accessibility'),{exact:true}).click();
  await session.page.getByText(value('Caption Styles'),{exact:true}).click();
  await session.page.getByText(value('Family'),{exact:true}).click();
  for(const key of ['Monospaced Serif','Proportional Serif','Monospaced Sans-Serif','Proportional Sans-Serif','Casual','Cursive','Small Capitals']){await expect(session.page.getByRole('menuitemradio',{name:value(key),exact:true})).toBeVisible();observed.add(key);}
  await session.page.keyboard.press('Escape');await session.page.keyboard.press('Escape');await session.page.keyboard.press('Escape');await session.page.keyboard.press('Escape');
  }else await session.page.keyboard.press('Escape');
  await expect(session.page.locator('.watch-page h1').first()).toHaveText('Home');
  await expect(session.page.locator('.description-card')).toContainText('Saved');
  await session.page.keyboard.press('t');
  await expect(player.locator('.watch-theater-button')).toHaveAttribute('aria-label',value('Exit theater mode'));observed.add('Exit theater mode');
  await expect(player.locator('.watch-theater-button')).toHaveAttribute('title',`${value('Default view')} (T)`);observed.add('Default view');
  assert.deepEqual(await layoutProblems(session.page),[],`${locale} player layout`);
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const row=review.messages[hash(key)];assert.equal(row?.source_hash,hash(reference[key]??key));assert.equal(row?.translation_hash,hash(value(key)));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Actual built watch-player control labels, cast disconnected status and theater toggle in isolated1100px browser; synthetic local media Play/Pause, original chapters Home/Settings in real menu; no provider network requests; literal provider title Home/description Saved retained, no application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: ${observed.size} actual watch-player labels verified; raw content retained, no application writes.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
