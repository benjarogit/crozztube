import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { setup,state,statePath,createTask,updateTask,reviewTask,validate,context,roles,showTask,suggestRoles } from '../chatdev/core.mjs';
const ids=['project-manager','orchestrator','engineer','analyst','qa','quality','architecture','security','performance','ux','assets','documentation','localization','repository','infrastructure'];
function fixture(){const root=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-test-'));execFileSync('git',['init','-q',root]);const run=args=>execFileSync('git',args,{cwd:root,stdio:'pipe'});run(['config','user.email','fixture@example.invalid']);run(['config','user.name','Fixture']);fs.mkdirSync(path.join(root,'harness/chatdev'),{recursive:true});fs.writeFileSync(path.join(root,'harness/plan.json'),JSON.stringify({schemaVersion:1,branch:'main',steps:[{id:1,title:'Fixture current'},{id:2,title:'Later'}]}));fs.writeFileSync(path.join(root,'harness/progress.json'),JSON.stringify({schemaVersion:1,activeStep:1,steps:[{id:1,status:'active'},{id:2,status:'pending'}]}));fs.writeFileSync(path.join(root,'harness/chatdev/roles.json'),JSON.stringify({schemaVersion:1,roles:ids.map(id=>({id,responsibility:id,inputs:['source'],outputs:['evidence'],quality:['checked'],handoff:['review']}))}));run(['add','.']);run(['commit','-qm','Fixture']);return {root,run,close:()=>fs.rmSync(root,{recursive:true,force:true})};}
const task=()=>({id:'test-task',objective:'Verify tooling',step:1,scope:'tooling',author:'developer-session',owner:'engineer',reviewers:['quality'],files:['tools/chatdev/core.mjs'],risks:[],dependencies:[],acceptance:['Tests pass'],checks:[{command:'node --test',result:'passed',evidence:'fixture results'}],approvals:[],openIssues:[],nextAction:'Independent review'});
const review=()=>({actor:'review-session',role:'quality',decision:'approved',evidence:['Read diff and test output']});
function withFixture(fn){const f=fixture();try{return fn(f);}finally{f.close();}}
test('all 15 roles validate; duplicate inventory fails',()=>withFixture(({root})=>{assert.equal(roles(root).length,15);fs.writeFileSync(path.join(root,'harness/chatdev/roles.json'),JSON.stringify({roles:Array(15).fill({id:'duplicate'})}));assert.throws(()=>roles(root));}));
test('setup repeats without losing state or changing harness',()=>withFixture(({root})=>{const progress=fs.readFileSync(path.join(root,'harness/progress.json'),'utf8');setup(root);createTask(root,task(),0);assert.equal(setup(root).revision,1);assert.equal(fs.readFileSync(path.join(root,'harness/progress.json'),'utf8'),progress);assert(!statePath(root).startsWith(path.join(root,'harness')));}));
test('restart reads shared context and persisted journal without chat history',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);const cli=fileURLToPath(new URL('../chatdev/cli.mjs',import.meta.url));const out=execFileSync(process.execPath,[cli,'context'],{cwd:root,encoding:'utf8'});const result=JSON.parse(out);assert.equal(result.authoritative.activeStep,1);assert.equal(result.memory.tasks[0].id,'test-task');assert.equal(result.memory.harnessGate.startsWith('not-granted'),true);}));
test('optimistic revisions reject stale updates without data loss',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);assert.throws(()=>updateTask(root,task(),0),/Revision conflict/);assert.equal(state(root).revision,1);}));
test('duplicates and unknown fields cannot overwrite a task',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);assert.throws(()=>createTask(root,task(),1),/already exists/);assert.throws(()=>updateTask(root,{...task(),harnessAccepted:true},1),/Unknown task fields/);assert.equal(state(root).revision,1);}));
test('concurrent writer lock fails closed and leaves existing lock intact',()=>withFixture(({root})=>{setup(root);const lock=statePath(root)+'.lock';fs.mkdirSync(lock);assert.throws(()=>createTask(root,task(),0),/locked/);assert(fs.existsSync(lock));assert.equal(state(root).revision,0);}));
test('failed operation releases its own lock and can be resumed',()=>withFixture(({root})=>{setup(root);assert.throws(()=>updateTask(root,task(),0),/Unknown task/);assert(!fs.existsSync(statePath(root)+'.lock'));assert.equal(createTask(root,task(),0).revision,1);}));
test('author and owner cannot claim independent approval',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);assert.throws(()=>reviewTask(root,'test-task',{...review(),actor:'developer-session'},1),/Independent reviewer/);assert.throws(()=>reviewTask(root,'test-task',{...review(),role:'engineer'},1),/Independent reviewer/);}));
test('failed and unverified checks, blockers and missing approval prevent review approval',()=>withFixture(({root})=>{setup(root);for(const field of ['checks','approvals','openIssues']){const t=task();t.id='task-'+field.toLowerCase();if(field==='checks')t.checks[0].result='not-verified';else t[field]=['open'];const revision=state(root).revision;createTask(root,t,revision);assert.throws(()=>reviewTask(root,t.id,review(),revision+1),/prevent approval/);}}));
test('review is source-bound, never a harness gate; edits invalidate it',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);reviewTask(root,'test-task',review(),1);assert.equal(validate(root).tasks[0].reviewStatus,'reviewed');assert.match(validate(root).harnessGate,/not-granted/);fs.writeFileSync(path.join(root,'new-source.mjs'),'export const changed=true;');assert.equal(validate(root).tasks[0].reviewStatus,'open');updateTask(root,task(),2);assert.deepEqual(state(root).tasks[0].reviews,[]);}));
test('later feature step, unsafe paths and credential dumps are rejected',()=>withFixture(({root})=>{setup(root);assert.throws(()=>createTask(root,{...task(),step:2},0),/current harness/);assert.throws(()=>createTask(root,{...task(),files:['../secret']},0),/Unsafe/);assert.throws(()=>createTask(root,{...task(),objective:'password=not-a-real-password'},0),/Potential secret/);assert.equal(state(root).revision,0);}));
test('two adapter callers use one journal and derive updated authoritative progress',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);const status=context(root);assert.equal(status.memory.revision,1);const p=path.join(root,'harness/progress.json');fs.writeFileSync(p,JSON.stringify({schemaVersion:1,activeStep:2,steps:[{id:1,status:'accepted'},{id:2,status:'active'}]}));assert.equal(context(root).authoritative.activeStep,2);assert.equal(state(root).tasks[0].step,1);assert.throws(()=>reviewTask(root,'test-task',review(),1),/Historical tasks/);assert.equal(JSON.parse(fs.readFileSync(p)).activeStep,2);}));

 test('show restores complete task handoff in a fresh caller',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);const cli=fileURLToPath(new URL('../chatdev/cli.mjs',import.meta.url));const result=JSON.parse(execFileSync(process.execPath,[cli,'show','test-task'],{cwd:root,encoding:'utf8'}));assert.equal(result.task.nextAction,'Independent review');assert.equal(result.task.objective,'Verify tooling');assert.deepEqual(result.task.checks,task().checks);}));
 test('routing is narrow for docs and includes specialists for code paths',()=>withFixture(({root})=>{assert.deepEqual(suggestRoles(root,['README.md']).roles,['project-manager','orchestrator','quality','documentation']);const r=suggestRoles(root,['app/Http/UploadController.php','resources/js/Player.jsx','docker/compose.yaml']);for(const id of ['security','architecture','qa','ux','localization','infrastructure'])assert(r.roles.includes(id));assert.equal(r.harnessPermission,false);}));
 test('mode changes invalidate source-bound review',()=>withFixture(({root})=>{setup(root);createTask(root,task(),0);reviewTask(root,'test-task',review(),1);assert.equal(validate(root).tasks[0].reviewStatus,'reviewed');fs.chmodSync(path.join(root,'harness/plan.json'),0o755);assert.equal(validate(root).tasks[0].reviewStatus,'open');}));
 test('malformed input and memory JSON never echo their source text',()=>withFixture(({root})=>{setup(root);const input=path.join(root,'malformed.json'),sentinel='DO_NOT_ECHO_SENTINEL';fs.writeFileSync(input,sentinel);const cli=fileURLToPath(new URL('../chatdev/cli.mjs',import.meta.url));try{execFileSync(process.execPath,[cli,'create',input,'0'],{cwd:root,stdio:['ignore','pipe','pipe']});assert.fail('Expected rejection');}catch(e){assert(!String(e.stdout).includes(sentinel));assert(!String(e.stderr).includes(sentinel));}fs.writeFileSync(statePath(root),sentinel);assert.throws(()=>state(root),e=>!e.message.includes(sentinel));}));
 test('exited lock owner requires quiesced manual recovery; active writer remains protected',()=>withFixture(({root})=>{setup(root);const lock=statePath(root)+'.lock';execFileSync(process.execPath,['-e',`const fs=require('node:fs');fs.mkdirSync(process.argv[1]);fs.writeFileSync(process.argv[1]+'/owner.json',JSON.stringify({pid:process.pid}));`,lock]);assert.throws(()=>createTask(root,task(),0),/locked/);assert(fs.existsSync(lock));fs.rmSync(lock,{recursive:true});assert.equal(createTask(root,task(),0).revision,1);fs.mkdirSync(lock);fs.writeFileSync(path.join(lock,'owner.json'),JSON.stringify({pid:process.pid}));assert.throws(()=>updateTask(root,task(),1),/locked/);assert(fs.existsSync(lock));}));

test('blank acceptance, check and review evidence cannot approve work',()=>withFixture(({root})=>{
 setup(root);
 for(const acceptance of [[''],['  ']])assert.throws(()=>createTask(root,{...task(),acceptance},0),/Invalid/);
 for(const field of ['command','evidence'])assert.throws(()=>createTask(root,{...task(),checks:[{...task().checks[0],[field]:'  '}]},0),/Invalid/);
 createTask(root,task(),0);
 assert.throws(()=>reviewTask(root,'test-task',{...review(),evidence:['  ']},1),/Invalid/);
 assert.equal(state(root).revision,1);
}));

test('documented task example can be created using the live step',()=>withFixture(({root})=>{
 const doc=fs.readFileSync(new URL('../chatdev/README.md',import.meta.url),'utf8');
 const example=JSON.parse(doc.match(/```json\n([\s\S]*?)\n```/)[1]);
 example.step=1;setup(root);createTask(root,example,0);assert.equal(state(root).tasks[0].id,example.id);
}));

test('shared native skill installs once, preserves unrelated files and refuses local edits',async()=>{
 const {installSkill}=await import('../chatdev/install-skill.mjs');
 const home=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-skill-'));
 try{
  const installed=installSkill({home});assert.equal(installed.status,'installed');
  const skill=path.join(installed.path,'SKILL.md'),before=fs.readFileSync(skill,'utf8');
  assert.match(before,/^---\nname: chatdev\ndescription: .+\n---/);
  assert.match(before,/tools\/chatdev\/cli.mjs project-manager/);
  fs.writeFileSync(path.join(installed.path,'personal-note.txt'),'keep');
  assert.equal(installSkill({home}).status,'unchanged');
  assert.equal(fs.readFileSync(path.join(installed.path,'personal-note.txt'),'utf8'),'keep');
  fs.appendFileSync(skill,'\nUser edit\n');assert.throws(()=>installSkill({home}),/modified/);
  assert.match(fs.readFileSync(skill,'utf8'),/User edit/);
 }finally{fs.rmSync(home,{recursive:true,force:true});}
});
test('skill installer rejects unmanaged directories, alternate registrations and symlinks',async()=>{
 const {installSkill}=await import('../chatdev/install-skill.mjs');
 for(const kind of ['unmanaged','alternate','symlink','file']){
  const home=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-skill-'));
  try{
   const target=path.join(home,'.agents/skills/chatdev');
   if(kind==='alternate')fs.mkdirSync(path.join(home,'.cursor/skills/chatdev'),{recursive:true});
   else {fs.mkdirSync(path.dirname(target),{recursive:true});if(kind==='symlink')fs.symlinkSync(home,target);else if(kind==='file')fs.writeFileSync(target,'keep');else fs.mkdirSync(target);}
   assert.throws(()=>installSkill({home}),/Existing|Unsafe/);
   if(kind==='file')assert.equal(fs.readFileSync(target,'utf8'),'keep');
  }finally{fs.rmSync(home,{recursive:true,force:true});}
 }
});
test('skill update is serialized and rolls back failed publication for a safe retry',async()=>{
 const {installSkill}=await import('../chatdev/install-skill.mjs');
 const home=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-skill-'));
 const source=path.join(home,'source');fs.cpSync(new URL('../chatdev/skill/',import.meta.url),source,{recursive:true});
 try{
  const initial=installSkill({home,source}),skill=path.join(initial.path,'SKILL.md');
  const original=fs.readFileSync(skill,'utf8');fs.writeFileSync(path.join(initial.path,'note'),'keep');
  fs.appendFileSync(path.join(source,'SKILL.md'),'\nNew managed version\n');
  const rename=fs.renameSync;
  try{fs.renameSync=(from,to)=>{if(to===initial.path&&from.includes('prepared-'))throw Error('Simulated publication failure');return rename(from,to);};assert.throws(()=>installSkill({home,source}),/Simulated/);}finally{fs.renameSync=rename;}
  assert.equal(fs.readFileSync(skill,'utf8'),original);assert.equal(fs.readFileSync(path.join(initial.path,'note'),'utf8'),'keep');
  assert.equal(installSkill({home,source}).status,'updated');assert.match(fs.readFileSync(skill,'utf8'),/New managed version/);
  assert.equal(installSkill({home,source}).status,'unchanged');
  fs.mkdirSync(initial.path+'.install-lock');assert.throws(()=>installSkill({home,source}),/locked/);assert(fs.existsSync(initial.path+'.install-lock'));
 }finally{fs.rmSync(home,{recursive:true,force:true});}
});
test('failed rollback preserves original backup even when another target exists',async()=>{
 const {installSkill}=await import('../chatdev/install-skill.mjs');
 const home=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-skill-'));
 const source=path.join(home,'source');fs.cpSync(new URL('../chatdev/skill/',import.meta.url),source,{recursive:true});
 try{
  const initial=installSkill({home,source});fs.writeFileSync(path.join(initial.path,'user-note'),'original');
  fs.appendFileSync(path.join(source,'SKILL.md'),'\nUpdate\n');
  const rename=fs.renameSync;
  try{fs.renameSync=(from,to)=>{if(to===initial.path){if(from.includes('prepared-')){fs.mkdirSync(to);fs.writeFileSync(path.join(to,'foreign'),'preserve');throw Error('Failed publication');}throw Error('Failed rollback');}return rename(from,to);};assert.throws(()=>installSkill({home,source}),/Failed rollback/);}finally{fs.renameSync=rename;}
  const lock=initial.path+'.install-lock';assert.equal(fs.readFileSync(path.join(lock,'previous/user-note'),'utf8'),'original');
  assert.equal(fs.readFileSync(path.join(initial.path,'foreign'),'utf8'),'preserve');assert.throws(()=>installSkill({home,source}),/locked/);
 }finally{fs.rmSync(home,{recursive:true,force:true});}
});
