import assert from 'node:assert/strict';
import { chromium, expect } from '@playwright/test';
import { layoutSession, layoutProblems } from './fixtures/readonly-layout.ts';

const browser=await chromium.launch({headless:true});
try {for(const touch of [false,true]){
  const session=await layoutSession(browser,{language:'de_de',width:390,touch,...(touch?{userAgent:'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36'}:{}),transform:(path,payload)=>path==='/api/upload-options'?{...payload,categories:[{id:9991,name:'Home'},{id:9992,name:'Settings'}],languages:[{id:9993,name:'Saved'}]}:payload});
  try {
    await session.context.route('**/api/video-imports/metadata',route=>route.fulfill({json:{metadata:{provider:'youtube',source_url:route.request().postDataJSON().url,title:'Home',description:'Saved',duration_seconds:120,captions:[],chapters:[],thumbnail_url:'https://example.test/thumbnail.jpg'}}}));
    await session.goto('/upload');
    const page=session.page;page.setDefaultTimeout(10000);
    await page.locator('.upload-import-url-row input').fill('https://www.youtube.com/watch?v=fixture1234');
    await expect(page.locator('input[value="Home"]')).toBeVisible();
    const category=page.locator('.upload-details-panel .upload-label').filter({hasText:'Kategorie'}).first().locator('..');
    const language=page.locator('.upload-details-panel .upload-label').filter({hasText:'Sprache'}).first().locator('..');
    if(touch){
      await category.locator('select').selectOption('9991');
      await language.locator('select').selectOption('9993');
      await expect(category.locator('option:checked')).toHaveText('Home');
      await expect(language.locator('option:checked')).toHaveText('Saved');
    }else{
      await category.locator('.adaptive-select__control').click();
      await page.getByRole('option',{name:'Home',exact:true}).click();
      await expect(category.locator('.adaptive-select__single-value')).toHaveText('Home');
      await language.locator('.adaptive-select__control').click();
      await page.getByRole('option',{name:'Saved',exact:true}).click();
      await expect(language.locator('.adaptive-select__single-value')).toHaveText('Saved');
    }
    assert.deepEqual(await layoutProblems(page),[]);
    assert.deepEqual(session.errors,[]);assert.deepEqual(session.writes,[]);
    console.log(`${touch?'Native touch':'Desktop react-select'}: authored category/language names retained at390px.`);
  }finally{await session.context.close();}
}}finally{await browser.close();}
