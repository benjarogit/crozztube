import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-romance-chapter-validation.json')));
const reference=applicationJson('/api/i18n/en',false).translations;
const requested=process.argv.find(x=>x.startsWith('--locale='))?.slice(9);
const locales=['fr_fr','fr_ca','es_es','es_ar','es_mx','pt_pt','pt_pt_ao90','pt_br','it_it','ca','gl_es','eo'].filter(x=>!requested||x===requested);
assert.ok(locales.length);
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const browser=await chromium.launch({headless:true});
try{for(const locale of locales){
 const session=await layoutSession(browser,{language:locale,width:390});
 const observed=new Set();
 const word=(key,vars={})=>{observed.add(key);const v=batch.messages[key],text=v[locale]??v[locale.split('_')[0]];return text.replace(/\{(\w+)\}/g,(_,name)=>vars[name]??`{${name}}`);};
 try{
  await session.context.route('**/api/video-imports/metadata',route=>{const body=route.request().postDataJSON();return route.fulfill({json:{metadata:{provider:body.provider,source_url:body.url,title:'Saved',description:'Home',duration_seconds:120,captions:[],chapters:[{start_time:0,title:'Home'},{start_time:60,title:'Settings'}],chapters_source:'provider'}}});});
  await session.goto('/upload');const page=session.page;
  await page.locator('.upload-import-url-row input').fill('https://www.youtube.com/watch?v=fixture1234');
  await expect(page.locator('input[value="Saved"]')).toBeVisible();
  await page.locator('.adaptive-select-root').nth(1).locator('.adaptive-select__control').click();
  await page.getByRole('option').last().click();
  await expect(page.locator('.upload-footer-actions .publish-button')).toBeEnabled();
  await page.locator('.upload-footer-actions .publish-button').click();
  await page.locator('.upload-wizard-actions .btn.primary').click();
  const rows=page.locator('.upload-chapter-row');await expect(rows).toHaveCount(2);
  const firstName=rows.nth(0).locator('input').nth(1),secondName=rows.nth(1).locator('input').nth(1),secondTime=rows.nth(1).locator('input').first();
  await expect(firstName).toHaveValue('Home');await expect(secondName).toHaveValue('Settings');
  await expect(firstName).toHaveAttribute('placeholder',word('Chapter {number} name',{number:1}));
  await expect(secondName).toHaveAttribute('placeholder',word('Chapter {number} name',{number:2}));
  await firstName.fill('');
  await expect(page.locator('.upload-chapter-error')).toHaveText(word('Add a name for chapter {number} or turn off Upload with chapters.',{number:1}));
  await firstName.fill('Home');
  await secondTime.fill('not-a-time');
  await expect(page.locator('.upload-chapter-error')).toHaveText(word('Chapter {number} has an invalid timestamp.',{number:2}));
  await secondTime.fill('0:00');
  await expect(page.locator('.upload-chapter-error')).toHaveText(word('Chapter timestamps must be in order and at least {seconds} seconds apart.',{seconds:1}));
  await secondTime.fill('2:00');
  await expect(page.locator('.upload-chapter-error')).toHaveText(word('Chapter {number} starts after the video ends.',{number:2}));
  await secondTime.fill('1:00');await expect(page.locator('.upload-chapter-error')).toHaveCount(0);
  assert.deepEqual(await layoutProblems(page),[],`${locale}: 390px chapter validation`);assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const values=batch.messages[key],value=values[locale]??values[locale.split('_')[0]],row=review.messages[hash(key)];assert.equal(row?.source_hash,hash(reference[key]??key));assert.equal(row?.translation_hash,hash(value));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'390px import review: original chapter names, localized numbered inputs, empty name, invalid timestamp, 1-second provider spacing, chapter after duration, correction clears error; no application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: ${observed.size} complete chapter messages verified in review and corrected error states.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
