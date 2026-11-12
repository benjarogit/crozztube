import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-romance-spanish-default-help.json')));
const reference=applicationJson('/api/i18n/en',false).translations;
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const browser=await chromium.launch({headless:true});
try{for(const locale of ['es_es','es_ar','es_cl','es_co','es_cr','es_do','es_ec','es_gt','es_mx','es_pe','es_pr','es_uy','es_ve']){
 const factory=applicationJson(`/api/admin/content-pages?locale=${locale}`,true).pages;
 const selected=factory.filter(page=>['privacy','terms','cookies','refund'].includes(page.key));
 assert.equal(selected.length,4);
 const session=await layoutSession(browser,{authenticated:false,language:locale,width:390,transform:(url,payload)=>{
  const definition=selected.find(page=>url.split('?')[0]===`/api/content-pages/${page.key}`);
  if(!definition||!payload.page)return payload;
  return {...payload,page:{...payload.page,...definition.default_content,source_locale:null,customized:false}};
 }});
 const observed=new Set();
 try{
  for(const page of selected){
   await session.goto(page.route);
   const expected=page.default_content;
   await expect(session.page.locator('.info-page h1')).toHaveText(expected.title);
   await expect(session.page.locator('.info-page')).toContainText(expected.subtitle);
   const contentKeys=[expected.title,expected.subtitle];
   for(const section of expected.sections){
    await expect(session.page.locator('.info-page')).toContainText(section.title);
    // Refund uses collapsible sections: open each local article before reading.
    const details=session.page.locator('details').filter({has:session.page.locator('summary',{hasText:section.title})});
    if(await details.count())await details.evaluateAll(items=>items.forEach(item=>{item.open=true;}));
    await expect(session.page.locator('.info-page')).toContainText(section.body);
    contentKeys.push(section.title,section.body);
   }
   for(const text of contentKeys){
    for(const[key,values]of Object.entries(batch.messages))if(text===(values[locale]??values.es))observed.add(key);
   }
   assert.deepEqual(await layoutProblems(session.page),[],`${locale}/${page.key}: narrow policy layout`);
  }
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const values=batch.messages[key],value=values[locale]??values.es,row=review.messages[hash(key)];assert.equal(row?.source_hash,hash(reference[key]??key));assert.equal(row?.translation_hash,hash(value));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Project-default privacy/terms/cookies/refund content rendered in isolated390px browser using admin API default_content only; existing saved documents untouched, no application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: ${observed.size} default help/policy messages rendered across4 policy pages; stored revisions untouched.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
