import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { applicationJson, layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const source=applicationJson('/api/i18n/en',false);
const scenarios={ca:{view:['visualització','visualitzacions'],user:['usuari','usuaris']},gl_es:{view:['visualización','visualizacións'],user:['usuario','usuarios']},eo:{view:['spektado','spektadoj'],user:['uzanto','uzantoj']}};
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const browser=await chromium.launch({headless:true});
try{for(const[locale,units]of Object.entries(scenarios)){
 const pack=applicationJson(`/api/i18n/${locale}`,false);
 const session=await layoutSession(browser,{authenticated:false,language:locale,width:390});
 try{
  await session.goto('/login');
  const inputs=Object.keys(units).flatMap(unit=>[0,1,2,5,21].map(n=>`${n} ${unit}s`));
  await session.page.evaluate(inputs=>{const group=document.createElement('section');group.id='romance-counts';for(const text of inputs){const span=document.createElement('span');span.textContent=text;group.append(span);}document.querySelector('.auth-card').append(group);},inputs);
  const expected=Object.entries(units).flatMap(([,forms])=>[0,1,2,5,21].map(n=>`${n} ${forms[n===1?0:1]}`));
  await expect(session.page.locator('#romance-counts span')).toHaveText(expected);
  assert.deepEqual(await layoutProblems(session.page),[],`${locale}: narrow count layout`);
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const unit of Object.keys(units))for(const key of [unit,`${unit}s`]){
    const row=review.messages[hash(`__plurals:${key}`)];
    assert.equal(row?.source_hash,hash(JSON.stringify(source.plurals[key])));
    assert.equal(row?.translation_hash,hash(JSON.stringify(pack.plurals[key])));
    row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Live UI count rendering: zero, one, two, five and twenty-one views/users, 390px, no page overflow or application writes'])];
   }
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: view/user singular and plural counts verified for 0, 1, 2, 5 and 21.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
