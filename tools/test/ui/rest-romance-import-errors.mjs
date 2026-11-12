import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-romance-import-api-errors.json')));
const reference=applicationJson('/api/i18n/en',false).translations;
const locales=['fr_fr','fr_ca','es_es','es_ar','es_mx','pt_pt','pt_pt_ao90','pt_br','it_it','ca','gl_es','eo'];
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const browser=await chromium.launch({headless:true});
try{for(const locale of locales){
 const session=await layoutSession(browser,{language:locale,width:390});let call=0;
 const observed=[];
 const expected=key=>{observed.push(key);const values=batch.messages[key];return values[locale]??values[locale.split('_')[0]];};
 try{
  await session.context.route('**/api/video-imports/metadata',async route=>{
   call++;if(call===1)return route.fulfill({status:422,json:{message:'The import provider returned invalid metadata.'}});
   if(call===2)return route.fulfill({status:409,json:{message:'This video has already been added.'}});
   const body=route.request().postDataJSON();return route.fulfill({json:{metadata:{provider:body.provider,source_url:body.url,title:'Saved',description:'Home',duration_seconds:120,captions:[],chapters:[]}}});
  });
  await session.goto('/upload');
  const page=session.page,input=page.locator('.upload-import-url-row input');
  await input.fill('https://www.youtube.com/watch?v=fixture1234');
  await expect(page.locator('.upload-error')).toContainText(expected('The import provider returned invalid metadata.'));
  await input.fill('https://www.youtube.com/watch?v=fixture5678');
  await expect(page.locator('.upload-error')).toContainText(expected('This video has already been added.'));
  await input.fill('https://www.youtube.com/watch?v=fixture9012');
  await expect(page.locator('input[value="Saved"]')).toBeVisible();
  await expect(page.locator('textarea').first()).toHaveValue('Home');
  await expect(page.locator('.upload-error')).toHaveCount(0);
  assert.deepEqual(await layoutProblems(page),[],`${locale}: import error layout`);
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const values=batch.messages[key],value=values[locale]??values[locale.split('_')[0]],row=review.messages[hash(key)];assert.equal(row?.source_hash,hash(reference[key]??key));assert.equal(row?.translation_hash,hash(value));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'390px URL-import flow: invalid metadata 422, duplicate 409, recovery with original provider title/description, no overflow or application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: invalid provider metadata and duplicate import errors verified, recovery preserves provider content.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
