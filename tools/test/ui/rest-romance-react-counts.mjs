import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {applicationJson,layoutSession,layoutProblems} from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-romance-count-patterns.json'))),reference=applicationJson('/api/i18n/en',false).patterns;
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const slug=applicationJson('/api/videos?per_page=50',false).data.find(video=>!video.is_short)?.slug;assert.ok(slug);
const selected=process.argv.find(value=>value.startsWith('--locale='))?.split('=')[1];
const locales=selected?[selected]:['fr_fr','fr_ca','es_es','es_ar','es_mx','pt_pt','pt_pt_ao90','pt_br','it_it','ca','gl_es','eo'];
const browser=await chromium.launch({headless:true});
try{for(const locale of locales){
 const patterns=(batch.metadata[locale]??batch.metadata[locale.split('_')[0]]).__patterns;
 let count=0;
 const session=await layoutSession(browser,{authenticated:false,language:locale,width:390,transform:(url,payload)=>url===`/api/videos/${slug}`?{...payload,player_ads:null,video:{...payload.video,title:'Home',description:'Saved',views_count:count,channel:{...payload.video.channel,name:'Settings',subscribers_count:count},video_url:null,video_sources:[],embed_url:null,playback_pending:false}}:payload});
 const observed=new Set();
 try{
  for(const n of [0,1,2,5,21]){
   count=n;await session.goto(`/watch/${slug}`);
   const category=new Intl.PluralRules(locale==='pt_pt_ao90'?'pt-PT-ao1990':locale.replaceAll('_','-')).select(n);
   for(const[unit,selector]of [['view','.watch-info > .muted-line'],['subscriber','.channel-subscriber-count']]){
    const key=`count_${unit}_${category}`,expected=patterns[key].replace('{count}',String(n));
    await expect(session.page.locator(selector).first()).toContainText(expected);observed.add(key);
   }
   await expect(session.page.locator('.watch-info h1')).toHaveText('Home');
   await expect(session.page.locator('.channel-identity strong [data-i18n-skip]').first()).toHaveText('Settings');
   assert.deepEqual(await layoutProblems(session.page),[],`${locale} count${n} at390px`);
  }
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const row=review.messages[hash(`__patterns:${key}`)];assert.equal(row?.source_hash,hash(JSON.stringify(reference[key])));assert.equal(row?.translation_hash,hash(JSON.stringify(patterns[key])));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Actual React watch-page LocalizedCount view/subscriber headings for0/1/2/5/21 at390px. Native plural-category templates with literal title Home/channel Settings preserved; no application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: actual React view/subscriber counts0/1/2/5/21 verified;${observed.size} observed templates.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
