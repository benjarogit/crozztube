import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {setMode,localPath,digest,indexDigest} from '../harness/workflow.mjs';

// These fixtures test the real adapter and Git/check/push lifecycle. The
// authority helper alone is replaced with an explicit fixture provider;
// production provenance/approval is covered by the helper's separate tests.
const git=(root,...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
async function fixture(fn){
  const home=fs.mkdtempSync(path.join(os.tmpdir(),'series-stable-adapter-')),root=path.join(home,'feature'),remote=path.join(home,'remote.git');fs.mkdirSync(root);
  const write=(name,text)=>{const file=path.join(root,name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,text);};
  try{
    git(home,'init','--bare',remote);git(root,'init','-b','codex/chatdev-integration');git(root,'config','user.name','Isolated adapter fixture');git(root,'config','user.email','fixture@example.invalid');git(root,'config','core.hooksPath',path.join(root,'.git/hooks'));git(remote,'config','core.hooksPath',path.join(remote,'hooks'));git(root,'remote','add','origin',remote);
    const authority=path.join(home,'authority.json'),checkBehavior=path.join(home,'change-policy-during-check');
    const plan={schemaVersion:1,branch:'main',remote:'origin',steps:Array.from({length:8},(_,i)=>({id:i+1,...(i===7?{checkpointChecks:['fixture-check']}:{})})),checks:{'fixture-check':{argv:[process.execPath,'tools/test/synthetic.mjs'],timeoutMs:5000}}};
    write('harness/plan.json',JSON.stringify(plan));write('harness/progress.json',JSON.stringify({schemaVersion:1,activeStep:8,steps:Array.from({length:8},(_,i)=>({id:i+1,status:i===7?'active':'accepted'}))}));
    write('tools/test/synthetic.mjs',`import fs from 'node:fs';if(fs.existsSync(${JSON.stringify(checkBehavior)})){const p=${JSON.stringify(authority)};const b=JSON.parse(fs.readFileSync(p));b.policyDigest='changed-during-check';fs.writeFileSync(p,JSON.stringify(b));}console.log('Isolated adapter check');\n`);write('resources/js/main.jsx','// Existing UI\n');write('build/manifest.json','{}\n');
    git(root,'add','.');git(root,'commit','-qm','Published fixture base');git(root,'push','-u','origin','codex/chatdev-integration');const base=git(root,'rev-parse','HEAD');
    git(root,'checkout','-b','codex/series-seasons');git(root,'push','-u','origin','codex/series-seasons');
    const binding={base,runtime:'fixture-runtime-v1',policyDigest:'fixture-policy-v1',remoteIdentity:'fixture-origin'};
    fs.writeFileSync(authority,JSON.stringify(binding));
    const helper=path.join(home,'authority.mjs');fs.writeFileSync(helper,`import fs from 'node:fs';export function selectStableBase(){const binding=JSON.parse(fs.readFileSync(${JSON.stringify(authority)},'utf8'));if(binding.revoked)throw Error('Fixture authority revoked');return {binding};}`);
    let source=fs.readFileSync(new URL('../harness/series-maintenance.mjs',import.meta.url),'utf8');
    source=source.replace("'./workflow.mjs'",JSON.stringify(new URL('../harness/workflow.mjs',import.meta.url).href));
    source=source.replace("'../chatdev/stable-series-base.mjs'",JSON.stringify(pathToFileURL(helper).href));
    const rootExpression="const runtimeRoot=()=>fileURLToPath(new URL('../..',import.meta.url));";
    assert(source.includes(rootExpression),'Fixture must match the real runtime-root expression');
    source=source.replace(rootExpression,`const runtimeRoot=()=>${JSON.stringify(home)};`);
    const adapter=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
    setMode(root,'implementing');
    const edit=()=>{write('resources/js/main.jsx','// Reviewed feature change\n');git(root,'add','.');};
    const changePolicy=patch=>fs.writeFileSync(authority,JSON.stringify({...binding,...patch}));
    await fn({home,root,remote,base,binding,authority,checkBehavior,adapter,write,edit,changePolicy});
  }finally{fs.rmSync(home,{recursive:true,force:true});}
}

test('stable adapter verifies and confirms real fixture push while retaining all configured checks',()=>fixture(({root,remote,adapter,edit,binding})=>{
  edit();adapter.verifyStableSeries(root);
  const proof=JSON.parse(fs.readFileSync(localPath(root,'series-verification.json'),'utf8'));assert.deepEqual(proof.tooling,binding);assert.deepEqual(proof.checks,[{id:'fixture-check',passed:true}]);
  adapter.publishStableSeries(root,'Reviewed fixture checkpoint');assert.equal(git(remote,'rev-parse','refs/heads/codex/series-seasons'),git(root,'rev-parse','HEAD'));
  const publication=JSON.parse(fs.readFileSync(localPath(root,'series-publication.json'),'utf8'));assert.deepEqual(publication.tooling,binding);
}));
test('moved parent tip does not import source or add a merge in stable mode; legacy stays strict',()=>fixture(({root,base,adapter,edit})=>{
  git(root,'checkout','codex/chatdev-integration');fs.mkdirSync(path.join(root,'tools/chatdev'),{recursive:true});fs.writeFileSync(path.join(root,'tools/chatdev/parent.mjs'),'// Unrelated new parent\n');git(root,'add','.');git(root,'commit','-qm','Parent advance');git(root,'push','origin','codex/chatdev-integration');git(root,'checkout','codex/series-seasons');
  edit();assert.throws(()=>adapter.seriesScope(root));assert.equal(adapter.stableSeriesScope(root).base,base);adapter.verifyStableSeries(root);adapter.publishStableSeries(root,'Stable fixture checkpoint');
  assert.equal(fs.existsSync(path.join(root,'tools/chatdev/parent.mjs')),false);assert.equal(git(root,'rev-list','--count','--merges',base+'..HEAD'),'0');
}));
test('legacy and stable proofs cannot be exchanged',()=>fixture(({root,adapter,edit})=>{
  edit();adapter.verifySeries(root);assert.throws(()=>adapter.publishStableSeries(root,'Wrong path'),/different execution path/);
  adapter.verifyStableSeries(root);assert.throws(()=>adapter.publishSeries(root,'Wrong path'),/different execution path/);
}));
test('changed policy after verification prevents commit and push',()=>fixture(({root,remote,adapter,edit,changePolicy})=>{
  edit();adapter.verifyStableSeries(root);const head=git(root,'rev-parse','HEAD');changePolicy({policyDigest:'fixture-policy-v2'});
  assert.throws(()=>adapter.publishStableSeries(root,'Stale proof'),/tooling proof is stale/);assert.equal(git(root,'rev-parse','HEAD'),head);assert.equal(git(remote,'rev-parse','refs/heads/codex/series-seasons'),head);
}));
test('changed staged source after verification prevents publication',()=>fixture(({root,adapter,edit,write})=>{
  edit();adapter.verifyStableSeries(root);write('resources/js/main.jsx','// Changed after verification\n');git(root,'add','.');assert.throws(()=>adapter.publishStableSeries(root,'Stale source'),/stale or incomplete/);
}));
test('authorization changing during an actual configured check prevents proof creation',()=>fixture(({root,adapter,checkBehavior,edit})=>{
  fs.writeFileSync(checkBehavior,'fixture-only trigger');edit();
  assert.throws(()=>adapter.verifyStableSeries(root),/authorization changed/);
  assert.equal(fs.existsSync(localPath(root,'series-verification.json')),false);
}));
test('policy revocation by real post-commit hook prevents the subsequent remote push',()=>fixture(({root,remote,adapter,authority,edit})=>{
  edit();adapter.verifyStableSeries(root);const before=git(remote,'rev-parse','refs/heads/codex/series-seasons');
  const hook=path.join(root,'.git/hooks/post-commit');fs.writeFileSync(hook,`#!/bin/sh\n${JSON.stringify(process.execPath)} -e 'const fs=require("fs");const p=${JSON.stringify(authority)};const b=JSON.parse(fs.readFileSync(p));b.revoked=true;fs.writeFileSync(p,JSON.stringify(b));'\n`,{mode:0o755});
  assert.throws(()=>adapter.publishStableSeries(root,'Revoked after commit'));
  assert.equal(git(remote,'rev-parse','refs/heads/codex/series-seasons'),before);
}));
