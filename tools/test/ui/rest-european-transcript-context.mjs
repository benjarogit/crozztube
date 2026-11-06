import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
import { messageHash } from '../../i18n/audit.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-european-transcript.json')));
const source=applicationJson('/api/i18n/en',false).translations;
const slug=applicationJson('/api/videos?per_page=50',false).data.find(v=>!v.is_short).slug;
const requested=process.argv.find(x=>x.startsWith('--locale='))?.slice(9);
const locales=applicationJson('/api/i18n',false).languages.map(row=>row.code).filter(l=>(batch.messages.Transcript[l]||batch.messages.Transcript[l.split('_')[0]])&&(!requested||l===requested));
const browser=await chromium.launch({headless:true});
try{for(const locale of locales){
  const observed=new Map();const word=k=>{const value=batch.messages[k][locale]||batch.messages[k][locale.split('_')[0]];observed.set(k,value);return value;};
  const session=await layoutSession(browser,{authenticated:false,language:locale,width:390,transform:(url,payload)=>url===`/api/videos/${slug}`?{...payload,player_ads:null,video:{...payload.video,title:'Home',description:'Settings',transcript_url:'/rest-european-transcript.txt'}}:payload});
  try{let release;let requests=0;const gate=new Promise(resolve=>release=resolve);
    await session.context.route('**/rest-european-transcript.txt',async route=>{requests++;if(requests===1){await gate;await route.fulfill({status:503,body:'Unavailable'});}else await route.fulfill({contentType:'text/plain',body:'Home\nSettings\nSave for later'});});
    await session.goto(`/watch/${slug}`);
    const page=session.page, panel=page.locator('.watch-transcript');
    await expect(panel.locator('summary')).toHaveText(word('Transcript'));
    await panel.locator('summary').click();
    await expect(panel.getByRole('status')).toHaveText(word('Loading transcript…'));
    release();
    await expect(panel.getByRole('alert')).toContainText(word('The transcript could not be loaded.'));
    await panel.getByRole('button').click();
    const body=panel.locator('.watch-transcript-text');
    await expect(body).toHaveAttribute('aria-label',word('Transcript text'));
    await expect(body).toHaveText('Home\nSettings\nSave for later');
    await expect(body).toHaveAttribute('data-i18n-skip','true');
    assert.equal(requests,2,`${locale}: explicit retry after HTTP failure`);
    assert.deepEqual(await layoutProblems(page),[],`${locale}: transcript panel at390px`);
    assert.deepEqual(session.errors,[],`${locale}: runtime errors`);
    assert.deepEqual(session.writes,[],`${locale}: no application writes`);
    if(process.argv.includes('--record')){const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));for(const[key,value]of observed){const row=review.messages[messageHash(key)];assert.equal(row?.source_hash,messageHash(source[key]??key));assert.equal(row?.translation_hash,messageHash(value));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Actual optional watch transcript panel at390px: summary, held loading request,503 failure, explicit retry and unchanged provider transcript body/accessible label; no overflow/runtime errors/application writes'])];}fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');}
    console.log(`${locale}: four transcript messages, loading/error/retry/success and literal provider body verified.`);
  }finally{await session.context.close();}
}}finally{await browser.close();}
