import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';
import { spawn, spawnSync, execFileSync } from 'node:child_process';

function refusal(overrides) {
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'series-runner-refusal-'));
  try {
    fs.mkdirSync(path.join(root,'tools/test'),{recursive:true});
    fs.mkdirSync(path.join(root,'bin'));
    fs.copyFileSync(new URL('./step-08-series.sh',import.meta.url),path.join(root,'tools/test/step-08-series.sh'));
    const log=path.join(root,'calls');
    for(const name of ['php','docker'])fs.writeFileSync(path.join(root,'bin',name),'#!/bin/sh\nprintf "%s\\n" "$*" >> "$SERIES_REFUSAL_LOG"\nexit 47\n',{mode:0o755});
    const env={...process.env,PATH:path.join(root,'bin')+':'+process.env.PATH,SERIES_REFUSAL_LOG:log,CROZZTUBE_PHP_CONTAINER:'',CROZZTUBE_UI_FIXTURE:'1',CROZZTUBE_SERIES_DISPOSABLE_DB:'1',APP_ENV:'testing',BASE_URL:'',...overrides};
    const result=spawnSync('bash',['tools/test/step-08-series.sh'],{cwd:root,env,encoding:'utf8',timeout:10000});
    assert.equal(result.error,undefined);
    return {...result,calls:fs.existsSync(log)?fs.readFileSync(log,'utf8').trim().split('\n'):[]};
  } finally {fs.rmSync(root,{recursive:true,force:true});}
}
test('a rejected local preflight stops before migrations, fixtures and test writes',()=>{
  const r=refusal({});assert.equal(r.status,47);
  assert.deepEqual(r.calls,['tools/test/php/series-runner-preflight.php']);
});
test('local runner refuses a supplied external application URL before invoking PHP',()=>{
  const r=refusal({BASE_URL:'https://fixture.invalid'});assert.equal(r.status,1);
  assert.deepEqual(r.calls,[]);assert.match(r.stderr,/own isolated application server/);
});
test('compose fixture misuse is refused before invoking migrations or PHP',()=>{
  const r=refusal({CROZZTUBE_PHP_CONTAINER:'synthetic-container'});assert.equal(r.status,1);
  assert.deepEqual(r.calls,[]);assert.match(r.stderr,/verified disposable/);
});
test('the compose path runs the pure safety checks without automatic migrations',()=>{
  const r=refusal({CROZZTUBE_PHP_CONTAINER:'synthetic-container',CROZZTUBE_UI_FIXTURE:'0'});
  assert.equal(r.status,47);
  assert.deepEqual(r.calls,['exec synthetic-container php tools/test/php/series-runner-safety-checks.php']);
});
async function serverScenario(occupied) {
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'series-runner-port-'));
  const foreign=http.createServer((req,res)=>{res.writeHead(200);res.end(req.url==='/up'?'healthy':'foreign instance');});
  await new Promise(resolve=>foreign.listen(0,'127.0.0.1',resolve));
  const port=foreign.address().port;
  if(!occupied)await new Promise(resolve=>foreign.close(resolve));
  try {
    fs.mkdirSync(path.join(root,'tools/test'),{recursive:true});fs.mkdirSync(path.join(root,'bin'));
    fs.copyFileSync(new URL('./step-08-series.sh',import.meta.url),path.join(root,'tools/test/step-08-series.sh'));
    // No Laravel/database in this fixture: exercise the real PHP server/router
    // identity and shell lifecycle independently of the separately tested DB.
    fs.writeFileSync(path.join(root,'index.php'),'<?php echo "healthy";');
    const log=path.join(root,'calls');
    const realPhp=execFileSync('sh',['-c','command -v php'],{encoding:'utf8'}).trim();
    fs.writeFileSync(path.join(root,'bin/php'),'#!/bin/sh\nprintf "%s\\n" "$1" >> "$SERIES_REFUSAL_LOG"\ncase "$1" in -r|-S) exec "$SERIES_REAL_PHP" "$@";; *) exit 0;; esac\n',{mode:0o755});
    fs.writeFileSync(path.join(root,'bin/node'),'#!/bin/sh\nprintf "%s\\n" "$*" >> "$SERIES_REFUSAL_LOG"\n',{mode:0o755});
    const child=spawn('bash',['tools/test/step-08-series.sh'],{cwd:root,env:{...process.env,PATH:path.join(root,'bin')+':'+process.env.PATH,SERIES_REFUSAL_LOG:log,SERIES_REAL_PHP:realPhp,CROZZTUBE_PHP_CONTAINER:'',CROZZTUBE_UI_FIXTURE:'0',CROZZTUBE_APP_PORT:String(port),BASE_URL:''}});
    let stderr='';child.stderr.on('data',b=>{stderr+=b;});child.stdout.resume();
    const timeout=setTimeout(()=>child.kill('SIGKILL'),10000);
    let status,signal;
    try {[status,signal]=await new Promise((resolve,reject)=>{child.on('error',reject);child.on('close',(code,signal)=>resolve([code,signal]));});} finally {clearTimeout(timeout);}
    assert.equal(signal,null,'Runner must finish without timing out');
    return {status,stderr,browserStarted:fs.readFileSync(log,'utf8').includes('series-ui-check.mjs')};
  } finally {if(foreign.listening)await new Promise(resolve=>foreign.close(resolve));fs.rmSync(root,{recursive:true,force:true});}
}
test('an occupied port cannot substitute another application for the runner server',async()=>{
  const r=await serverScenario(true);
  assert.equal(r.status,1);assert.match(r.stderr,/isolated application server failed to start/);
  assert.equal(r.browserStarted,false,'No browser may start against the foreign server');
});
test('the runner accepts its own live server identity and reaches the browser command',async()=>{
  const r=await serverScenario(false);
  assert.equal(r.status,0);assert.equal(r.browserStarted,true);
});
test('both workflow triggers include the actual series browser and runner safety sources',()=>{
  const workflow=fs.readFileSync(new URL('../../.github/workflows/chatdev.yml',import.meta.url),'utf8');
  for(const event of ['push','pull_request']) {
    const block=workflow.split(`  ${event}:\n`)[1]?.split(/^  [a-z_]+:/m)[0];assert(block);
    for(const file of ['resources/js/seriesPlayback.js','resources/js/styles.css','tools/test/ui/series-ui-check.mjs','tools/test/ui/fixtures/readonly-layout.ts','tools/test/php/series-runner-preflight.php','tools/test/php/series-runner-safety-checks.php','tools/test/series-runner-safety.test.mjs'])assert(block.includes(`'${file}'`),`${event} missing ${file}`);
  }
});
