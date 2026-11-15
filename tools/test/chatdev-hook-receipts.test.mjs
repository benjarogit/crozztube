import test from 'node:test';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';import os from 'node:os';import path from 'node:path';import assert from 'node:assert/strict';
import {execFileSync,spawn,spawnSync} from 'node:child_process';
const source=fileURLToPath(new URL('../..',import.meta.url));
test('receipt writer serialized persistence, strict recovery and denial/Stop retention',async()=>{
const parent=fs.mkdtempSync(path.join(os.tmpdir(),'hook-repair-fixtures-'));let checks=0;
const fixture=name=>{const root=path.join(parent,name);fs.mkdirSync(root);execFileSync('git',['init','-q',root]);fs.mkdirSync(path.join(root,'harness'));
 fs.writeFileSync(path.join(root,'harness/plan.json'),JSON.stringify({schemaVersion:1,steps:[{id:1}]}));fs.writeFileSync(path.join(root,'harness/progress.json'),JSON.stringify({schemaVersion:1,activeStep:1,steps:[{id:1,status:'active'}]}));
 for(const file of ['tools/harness/workflow.mjs','.codex/hooks.json','.cursor/hooks.json']){fs.mkdirSync(path.dirname(path.join(root,file)),{recursive:true});fs.copyFileSync(path.join(source,file),path.join(root,file));}
 fs.copyFileSync(path.join(source,'tools/harness/codex-hook.mjs'),path.join(root,'tools/harness/codex-hook.mjs'));
 const receipt=path.join(root,'.git/crozztube-harness/hook-receipts.jsonl');fs.mkdirSync(path.dirname(receipt),{recursive:true,mode:0o700});return{root,receipt};};
const input=(id,event='PreToolUse')=>JSON.stringify({hook_event_name:event,session_id:'isolated-fixture-'+id,tool_name:'exec_command',tool_input:{cmd:'node tools/harness/cli.mjs deny-probe'},command:'node tools/harness/cli.mjs deny-probe'});
const run=(f,event='PreToolUse',body=input('one',event),extra=[])=>{const r=spawnSync(process.execPath,[...extra,'tools/harness/codex-hook.mjs',event],{cwd:f.root,input:body,encoding:'utf8',timeout:10000});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);};
const denied=o=>assert.equal(o.hookSpecificOutput?.permissionDecision,'deny');
try{
 const c=fixture('concurrent');await Promise.all(Array.from({length:24},(_,i)=>new Promise((resolve,reject)=>{const p=spawn(process.execPath,['tools/harness/codex-hook.mjs','SessionStart'],{cwd:c.root,stdio:['pipe','pipe','pipe']});let out='';p.stdout.on('data',x=>out+=x);p.stderr.resume();p.on('error',reject);p.on('close',code=>{try{assert.equal(code,0);assert.deepEqual(JSON.parse(out),{});resolve();}catch(e){reject(e);}});p.stdin.end(input(i,'SessionStart'));})));
 let lines=fs.readFileSync(c.receipt,'utf8').trim().split('\n').map(JSON.parse);assert.equal(lines.length,24);assert.equal(new Set(lines.map(x=>x.session)).size,24);assert.equal(fs.statSync(c.receipt).mode&0o777,0o600);checks++;
 fs.writeFileSync(c.receipt,Array.from({length:500},(_,i)=>JSON.stringify({event:'fixture',index:i})).join('\n')+'\n');run(c,'SessionStart');lines=fs.readFileSync(c.receipt,'utf8').trim().split('\n').map(JSON.parse);assert.equal(lines.length,500);assert.equal(lines[0].index,1);checks++;
 const bad=fixture('corrupt');const corrupt='{"event":"fixture"}\ntruncated-fixture';fs.writeFileSync(bad.receipt,corrupt);denied(run(bad));assert.equal(fs.readFileSync(bad.receipt,'utf8'),corrupt);assert.equal(run(bad,'beforeShellExecution').permission,'deny');checks++;
 const advance=JSON.parse(input('advance'));advance.tool_input.cmd='node tools/harness/cli.mjs begin 9';
 const advanceOutput=run(bad,'PreToolUse',JSON.stringify(advance));denied(advanceOutput);assert.match(advanceOutput.hookSpecificOutput.permissionDecisionReason,/current step is not accepted/);assert.equal(fs.readFileSync(bad.receipt,'utf8'),corrupt);checks++;
 const mod=await import('file://'+path.join(bad.root,'tools/harness/workflow.mjs'));assert.equal(mod.runtimeEvidence(bad.root),false);
 const ordinary=JSON.parse(input('archive-policy'));ordinary.tool_input.cmd='node tools/harness/codex-hook.mjs --archive-corrupt-receipts';
 const recoveryPolicy=run(bad,'PreToolUse',JSON.stringify(ordinary));assert.equal(recoveryPolicy.hookSpecificOutput,undefined);assert.match(recoveryPolicy.systemMessage,/could not validate/);assert.equal(mod.runtimeEvidence(bad.root),false);assert.equal(fs.readFileSync(bad.receipt,'utf8'),corrupt);checks++;
 ordinary.hook_event_name='beforeShellExecution';ordinary.command=ordinary.tool_input.cmd;assert.equal(run(bad,'beforeShellExecution',JSON.stringify(ordinary)).permission,'allow');checks++;
 const archive=run(bad,'--archive-corrupt-receipts','');assert.equal(archive.archived,true);assert.equal(archive.runtimeEvidenceRestored,false);assert.equal(fs.existsSync(bad.receipt),false);assert.equal(mod.runtimeEvidence(bad.root),false);
 const archiveDir=fs.readdirSync(path.dirname(bad.receipt)).find(n=>n.startsWith('hook-receipts-corrupt-'));const saved=path.join(path.dirname(bad.receipt),archiveDir,'original.jsonl');assert.equal(fs.readFileSync(saved,'utf8'),corrupt);assert.equal(fs.statSync(saved).mode&0o777,0o600);checks++;
 denied(run(bad));assert.equal(mod.runtimeEvidence(bad.root),true);fs.appendFileSync(path.join(bad.root,'tools/harness/codex-hook.mjs'),'\n// fixture digest change\n');assert.equal(mod.runtimeEvidence(bad.root),false);checks++;
 const stop=fixture('stop');execFileSync('git',['-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--allow-empty','-qm','isolated fixture'],{cwd:stop.root});
const stopModule=await import('file://'+path.join(stop.root,'tools/harness/workflow.mjs'));stopModule.setMode(stop.root,'implementing');fs.writeFileSync(stop.receipt,corrupt);
const stopOutput=run(stop,'Stop',JSON.stringify({hook_event_name:'Stop',session_id:'isolated-fixture-stop',turn_id:'isolated-fixture-turn'}));assert.equal(stopOutput.decision,'block');assert.match(stopOutput.reason,/finish the current step/);assert.equal(fs.readFileSync(stop.receipt,'utf8'),corrupt);checks++;
const stopAgain=run(stop,'Stop',JSON.stringify({hook_event_name:'Stop',stop_hook_active:true,turn_id:'isolated-fixture-turn'}));assert.equal(stopAgain.decision,undefined);assert.equal(stopModule.localState(stop.root).mode,'blocked');checks++;
const locked=fixture('locked');fs.mkdirSync(locked.receipt+'.lock');denied(run(locked));assert.equal(fs.existsSync(locked.receipt),false);assert.equal(fs.existsSync(locked.receipt+'.lock'),true);checks++;
 const fault=fixture('fault');fs.writeFileSync(fault.receipt,'{"event":"fixture"}\n');const loader=path.join(fault.root,'fault.mjs');fs.writeFileSync(loader,"import fs from 'node:fs';fs.renameSync=()=>{throw Error('isolated injected I/O fault')};");denied(run(fault,'PreToolUse',input('fault'),['--import',loader]));assert.equal(fs.readFileSync(fault.receipt,'utf8'),'{"event":"fixture"}\n');assert.equal(fs.readdirSync(path.dirname(fault.receipt)).filter(n=>n.startsWith('.hook-receipt-')).length,0);checks++;
 denied(run(fault,'PreToolUse','{malformed-fixture'));checks++;
 console.log(JSON.stringify({fixtureOnly:true,checksPassed:checks,concurrentWorkers:24,productionReceiptsTouched:false,realRuntimeClaim:false}));
}finally{fs.rmSync(parent,{recursive:true,force:true});}

});
