import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import assert from 'node:assert/strict';
import {chromium,expect} from '@playwright/test';
import {applicationJson,layoutSession,layoutProblems} from './fixtures/readonly-layout.ts';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..');
const batch=JSON.parse(fs.readFileSync(path.join(root,'tools/i18n/batches/rest-romance-password-captcha.json'))),reference=applicationJson('/api/i18n/en',false).translations;
const hash=value=>createHash('sha256').update(String(value)).digest('hex');
const selected=process.argv.find(v=>v.startsWith('--locale='))?.split('=')[1];
const locales=selected?[selected]:['fr_fr','fr_ca','es_es','es_ar','es_mx','pt_pt','pt_pt_ao90','pt_br','it_it','ca','gl_es','eo'];
const browser=await chromium.launch({headless:true});
try{for(const locale of locales){
 const value=key=>batch.messages[key]?.[locale]??batch.messages[key]?.[locale.split('_')[0]];
 const session=await layoutSession(browser,{authenticated:false,language:locale,width:390,transform:(url,payload)=>url==='/api/integrations/public'?{...payload,auth:{...payload.auth,registration:{...payload.auth?.registration,enabled:true,phoneEnabled:false,usernameRequired:false,confirmationMethod:'email',passwordStrengthEnabled:true,passwordStrengthLevel:10,captchaEnabled:false}}}:payload});
 await session.context.route('**/api/auth/registration-email-challenge',route=>route.fulfill({status:200,json:{expires_in:180,resends_remaining:3}}));
 await session.context.route('**/api/auth/registration-availability?*',route=>route.fulfill({status:200,json:{email:{valid:true,available:true},name:{valid:true,available:true}}}));
 const observed=new Set();
 try{
  await session.goto('/register');
  await session.page.locator('input[type=email]').fill('literal-home@example.invalid');
  await expect(session.page.locator('form button[type=submit],form button.btn.primary.full').last()).toBeEnabled();
  await session.page.locator('form button[type=submit],form button.btn.primary.full').last().click();
  const password=session.page.locator('input[type=password]').first();await expect(password).toBeVisible();
  const timer=session.page.locator('.phone-code-field small');await expect(timer).toContainText(/\d\d:\d\d/);
  const text=await timer.textContent(),time=text.match(/\d\d:\d\d/)[0];assert.equal(text,value('Code expires in {time}').replace('{time}',time));observed.add('Code expires in {time}');
  const scenarios=[['a',['Password needs work.','Add at least 6 characters.']],['123456',['Password needs work.','Add at least 8 characters.','Add a letter.','Add an uppercase letter.','Add a lowercase letter.','Add a symbol.','Use a longer password for this strength level.']],['abcdef',['Password needs work.','Add a number.']],['AAaa1122@@bbCC33DD',['Password looks good.']]];
  for(const[input,keys]of scenarios){await password.fill(input);for(const key of keys){await expect(session.page.locator('.password-feedback')).toContainText(value(key));observed.add(key);}}
  assert.deepEqual(await layoutProblems(session.page),[],`${locale}: password UI at390px`);
  assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
  if(process.argv.includes('--record')){
   const file=path.join(root,'tools/i18n/reviews',`${locale}.json`),review=JSON.parse(fs.readFileSync(file));
   for(const key of observed){const row=review.messages[hash(key)];assert.equal(row?.source_hash,hash(reference[key]??key));assert.equal(row?.translation_hash,hash(value(key)));row.status='verified';row.verified_at=new Date().toISOString();row.contexts=[...new Set([...row.contexts,'Actual registration password-feedback and complete countdown in isolated390px browser: short/numeric/lowercase/strong inputs exercise ASCII A–Z/a–z/0–9 hints; verification challenge/availability fixture-only, no actual code sent or account created; no application writes'])];}
   fs.writeFileSync(file,JSON.stringify(review,null,2)+'\n');
  }
  console.log(`${locale}: ${observed.size} password/countdown messages observed in real registration form; no account/code writes.`);
 }finally{await session.context.close();}
}}finally{await browser.close();}
