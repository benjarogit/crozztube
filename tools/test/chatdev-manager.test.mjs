import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { setup,createTask,reviewTask,state,statePath,git,roles,suggestRoles } from '../chatdev/core.mjs';
import { manager } from '../chatdev/project-manager.mjs';

function fixture(fn){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-manager-'));
  try{
    const g=args=>execFileSync('git',args,{cwd:root,stdio:'pipe'});g(['init','-q']);g(['config','user.name','Fixture']);g(['config','user.email','fixture@example.invalid']);
    fs.mkdirSync(path.join(root,'harness/chatdev'),{recursive:true});
    fs.copyFileSync(fileURLToPath(new URL('../../harness/chatdev/roles.json',import.meta.url)),path.join(root,'harness/chatdev/roles.json'));
    fs.writeFileSync(path.join(root,'harness/plan.json'),JSON.stringify({schemaVersion:1,steps:[{id:1,title:'Accepted fixture'},{id:2,title:'Current fixture'},{id:3,title:'Later fixture'}]}));
    fs.writeFileSync(path.join(root,'harness/progress.json'),JSON.stringify({schemaVersion:1,activeStep:2,steps:[{id:1,status:'accepted'},{id:2,status:'active'},{id:3,status:'pending'}]}));
    g(['add','.']);g(['commit','-qm','Fixture']);setup(root);return fn(root);
  }finally{fs.rmSync(root,{recursive:true,force:true});}
}
const task=(id,extra={})=>({id,objective:'Resume approved tooling',step:2,scope:'tooling',author:'engineer-session',owner:'engineer',reviewers:['quality'],files:['tools/chatdev/example.mjs'],risks:[],dependencies:[],acceptance:['Focused checks pass'],checks:[],approvals:[],openIssues:[],nextAction:'Continue the existing tooling task',...extra});
const add=(root,t)=>createTask(root,t,state(root).revision);
const ready=root=>({head:git(root,['rev-parse','HEAD']),status:'READY',changes:[],blockers:[]});
const approve=(root,id)=>reviewTask(root,id,{actor:'reviewer-session',role:'quality',decision:'approved',evidence:['Independent review of current fixture']},state(root).revision);
test('15 canonical roles include project manager and routing always involves coordination',()=>fixture(root=>{const expected=['project-manager','orchestrator','engineer','analyst','qa','quality','architecture','security','performance','ux','assets','documentation','localization','repository','infrastructure'];assert.deepEqual(roles(root).map(r=>r.id).sort(),expected.sort());assert(suggestRoles(root,['README.md']).roles.includes('project-manager'));}));
test('coordination derives current status and has no mutations or advancement',()=>fixture(root=>{add(root,task('active'));const before=fs.readFileSync(statePath(root),'utf8'), progress=fs.readFileSync(path.join(root,'harness/progress.json'),'utf8');const r=manager(root,ready(root));assert.equal(r.activeStep.id,2);assert.equal(r.nextAction.action,'resume-existing-task');assert.equal(r.nextAction.task,'active');assert.equal(r.registeredRoles.length,15);assert.equal(fs.readFileSync(statePath(root),'utf8'),before);assert.equal(fs.readFileSync(path.join(root,'harness/progress.json'),'utf8'),progress);assert.match(r.permission,/No begin/);}));
test('dependency ordering resumes existing predecessor rather than blocked child',()=>fixture(root=>{add(root,task('child',{dependencies:['parent']}));add(root,task('parent'));const r=manager(root,ready(root));assert.deepEqual(r.priorities.map(t=>t.id),['parent','child']);assert.equal(r.nextAction.task,'parent');assert(r.priorities[1].blockers.includes('DEPENDENCY_PENDING:parent'));}));
test('unknown dependencies and cycles stay explicit without starting new features',()=>fixture(root=>{add(root,task('a',{dependencies:['b']}));add(root,task('b',{dependencies:['a']}));add(root,task('unknown',{dependencies:['missing']}));const r=manager(root,ready(root));assert(r.priorities.find(t=>t.id==='a').blockers.includes('DEPENDENCY_CYCLE'));assert(r.priorities.find(t=>t.id==='b').blockers.includes('DEPENDENCY_CYCLE'));assert(r.taskBlockers.some(t=>t.code==='UNKNOWN_DEPENDENCY:missing'));assert.equal(r.nextAction.action,'resolve-current-task-blockers');}));
test('open permissions block affected work; independent current task may continue',()=>fixture(root=>{add(root,task('needs-approval',{approvals:['Await user authorization']}));add(root,task('independent'));const r=manager(root,ready(root));assert.equal(r.priorities[0].status,'blocked');assert.equal(r.nextAction.task,'independent');}));
test('failed and unavailable tests are routed back to implementation or QA',()=>fixture(root=>{add(root,task('fix',{checks:[{command:'fixture check',result:'failed',evidence:'Observed failure'}]}));let r=manager(root,ready(root));assert.equal(r.priorities[0].status,'needs-fix');assert.equal(r.nextAction.role,'engineer');const next=task('qa',{checks:[{command:'fixture check',result:'not-verified',evidence:'Runtime not available'}]});add(root,next);assert.equal(manager(root,ready(root)).priorities[1].status,'needs-tests');}));
test('historical task remains preserved and does not reopen an accepted step',()=>fixture(root=>{const s=state(root);s.tasks.push({...task('historical'),step:1,reviews:[],updatedAt:new Date().toISOString()});fs.writeFileSync(statePath(root),JSON.stringify(s));add(root,task('current',{dependencies:['historical']}));const r=manager(root,ready(root));assert.equal(r.historical[0].status,'historical');assert.equal(r.historical[0].acceptedStep,true);assert.equal(r.priorities.length,1);assert.equal(r.priorities[0].status,'ready-to-resume');assert.equal(r.nextAction.task,'current');assert.equal(state(root).tasks[0].id,'historical');}));
test('reviewed work stays reviewed-awaiting-harness rather than being reimplemented',()=>fixture(root=>{add(root,task('done',{checks:[{command:'fixture',result:'passed',evidence:'Observed focused pass'}]}));approve(root,'done');add(root,task('next',{dependencies:['done']}));const r=manager(root,ready(root));assert.equal(r.priorities[0].status,'reviewed-awaiting-harness');assert.equal(r.priorities[1].blockers.length,0);assert.equal(r.nextAction.task,'next');}));
test('pending PR and runtime role gates take precedence; READY is never inferred',()=>fixture(root=>{add(root,task('active'));const head=ready(root).head;for(const reason of ['CI_PENDING','MISSING_INDEPENDENT_ROLES','RUNTIME_PREREQUISITE_UNVERIFIED']){const r=manager(root,{head,status:'BLOCKED',blockers:[reason],changes:[]});assert(r.blockers.includes(reason));assert.equal(r.nextAction.action,'resolve-current-gates');assert.equal(r.nextAction.role,reason.includes('RUNTIME')?'qa':'repository');}}));
test('stored private audit is read but stale and malformed records cannot promote READY',()=>fixture(root=>{add(root,task('active'));const directory=path.dirname(statePath(root)), file=path.join(directory,'pr-audit-3.json');fs.writeFileSync(file,JSON.stringify({snapshot:{collectedAt:'2026-01-01'},result:{head:'0'.repeat(40),status:'READY'}}));assert(manager(root).blockers.includes('PR_AUDIT_STALE'));fs.writeFileSync(file,'not valid JSON');const r=manager(root);assert(r.blockers.includes('PR_AUDIT_INVALID'));assert(r.blockers.includes('PR_AUDIT_MISSING'));}));
test('fresh supplied audit takes precedence over stale private snapshot',()=>fixture(root=>{const directory=path.dirname(statePath(root));fs.writeFileSync(path.join(directory,'pr-audit-3.json'),JSON.stringify({result:{head:'old',status:'BLOCKED'}}));const r=manager(root,ready(root));assert.equal(r.blockers.length,0);assert.equal(r.prAudit.source,'supplied');assert.equal(r.nextAction.action,'inspect-current-step');}));
test('two PRs sharing a current head require an explicit fresh audit selection',()=>fixture(root=>{
  const directory=path.dirname(statePath(root)), head=ready(root).head;
  fs.writeFileSync(path.join(directory,'pr-audit-3.json'),JSON.stringify({snapshot:{pr:3,collectedAt:'2026-01-01'},result:{head,status:'BLOCKED',blockers:['CI_PENDING']}}));
  fs.writeFileSync(path.join(directory,'pr-audit-4.json'),JSON.stringify({snapshot:{pr:4,collectedAt:'2026-01-02'},result:{head,status:'READY'}}));
  const r=manager(root);
  assert(r.blockers.includes('PR_AUDIT_AMBIGUOUS'));
  assert.equal(r.prAudit,null);
  assert.equal(r.nextAction.action,'select-current-pr');
  assert.match(r.nextAction.instruction,/fresh pr-audit/);
  const explicit=manager(root,{head,status:'BLOCKED',blockers:['CI_PENDING'],changes:[]});
  assert(!explicit.blockers.includes('PR_AUDIT_AMBIGUOUS'));
  assert(explicit.blockers.includes('CI_PENDING'));
  assert.equal(manager(root,ready(root)).nextAction.action,'inspect-current-step');
}));

// Real remote writes are not made by these fixtures.
import { issueDecision, applyIssue, bodyHash } from '../chatdev/github-lifecycle.mjs';
const lifecycleFixture = () => {
  const issue={number:12,body:'Scoped package criterion',state:'open',updated_at:'v1'};
  const task={id:'package',acceptance:['Criterion'],checks:[{result:'passed'}],openIssues:[],approvals:[]};
  const binding={number:12,bodyHash:bodyHash(issue.body),taskId:task.id,criteria:[{text:'Criterion',result:'passed',evidence:['Actual observed result']}]};
  return {issue,task,binding,audit:{status:'READY'}};
};
const decide = f => issueDecision(f.issue,f.binding,f.task,'reviewed',f.audit,true,[]);
test('lifecycle closes only fully mapped independently reviewed accepted work packages',()=>{
  const f=lifecycleFixture();assert.equal(decide(f).action,'close');
  for(const mutate of [f=>f.binding.bodyHash='stale',f=>f.task.openIssues=['Cursor unavailable'],f=>f.task.approvals=['merge approval'],f=>f.task.checks=[],f=>f.task.checks[0].result='not-verified',f=>f.binding.criteria[0].result='not-verified',f=>f.binding.criteria[0].evidence=[],f=>f.binding.criteria=[],f=>f.binding.criteria[0].text='different',f=>f.audit.status='BLOCKED']){const x=lifecycleFixture();mutate(x);assert.equal(decide(x).action,'keep-open');}
});
test('lifecycle publication review and closed-unmerged dependencies cannot be inferred',()=>{
  const f=lifecycleFixture();
  assert.equal(issueDecision(f.issue,f.binding,f.task,'open',f.audit,true,[]).action,'keep-open');
  assert.equal(issueDecision(f.issue,f.binding,f.task,'reviewed',f.audit,false,[]).action,'keep-open');
  assert.equal(issueDecision(f.issue,f.binding,f.task,'reviewed',f.audit,true,[3]).action,'keep-open');
  assert.throws(()=>issueDecision({...f.issue,pull_request:{}},f.binding,f.task,'reviewed',f.audit,true,[]),/never a PR/);
});
function remoteFixture({human=false,duplicate=false,failClose=false,change=false}={}){
  const {issue}=lifecycleFixture();const writes=[];let comments=human?[{id:1,user:{login:'other'},body:'<!-- crozztube-chatdev:work-package-status:v1 --> human'}]:[];
  const body='<!-- crozztube-chatdev:work-package-status:v1 -->\nFixture';
  if(duplicate)comments=[1,2].map(id=>({id,user:{login:'bot'},body}));
  const api=(endpoint,method='GET',data)=>{
    if(endpoint.endsWith('/comments?per_page=100'))return [structuredClone(comments)];
    if(method==='POST'){writes.push([endpoint,method]);comments.push({id:3,user:{login:'bot'},body:data.body});issue.updated_at='v2';if(change)issue.body='Changed by user';return {};}
    if(method==='PATCH'){writes.push([endpoint,method]);if(data.state){if(failClose)throw Error('API failure');issue.state=data.state;}else comments.find(c=>endpoint.endsWith('/'+c.id)).body=data.body;return {};}
    return structuredClone(issue);
  };return {issue,api,writes,body,comments:()=>comments};
}
test('lifecycle repeated sync is idempotent and preserves human marker comments',()=>{
  const f=remoteFixture({human:true}),d={number:12,action:'keep-open'};
  applyIssue(f.api,'owner/repo',structuredClone(f.issue),d,f.body,'bot');
  applyIssue(f.api,'owner/repo',structuredClone(f.issue),d,f.body,'bot');
  assert.equal(f.writes.length,1);assert.equal(f.comments()[0].user.login,'other');
});
test('lifecycle confirms closure and never closes partial decisions',()=>{
  const f=remoteFixture();assert.equal(applyIssue(f.api,'owner/repo',structuredClone(f.issue),{action:'close'},f.body,'bot').action,'close');assert.equal(f.issue.state,'closed');
  const g=remoteFixture();applyIssue(g.api,'owner/repo',structuredClone(g.issue),{action:'keep-open'},g.body,'bot');assert.equal(g.issue.state,'open');
});
test('lifecycle duplicate comments concurrent changes and API failures remain visible',()=>{
  for(const options of [{duplicate:true},{change:true},{failClose:true}]){const f=remoteFixture(options);assert.throws(()=>applyIssue(f.api,'owner/repo',structuredClone(f.issue),{action:'close'},f.body,'bot'));assert.equal(f.issue.state,'open');}
});
test('lifecycle no evidence can silently convert missing criteria into a checkmark',()=>{
  const f=lifecycleFixture();
  for(const criteria of [null,{},[{text:'Criterion',result:'passed',evidence:[' ']}],[{text:'Criterion',result:'passed',evidence:['proof']},{text:'Extra',result:'passed',evidence:['proof']}]]){
    f.binding.criteria=criteria;assert.equal(decide(f).action,'keep-open');
  }
  const original=remoteFixture(), changed=structuredClone(original.issue);changed.updated_at='other';
  assert.throws(()=>applyIssue(original.api,'owner/repo',changed,{action:'close'},original.body,'bot'),/changed/);
  assert.equal(original.writes.length,0);
});
import { safeBinding, dependenciesFor } from '../chatdev/github-lifecycle.mjs';
test('publication rejects sensitive evidence without printing it and permits public commit IDs',()=>{
  for(const value of [['auth','token'].join('_')+'='+'fixture-value','https://example.invalid/?'+'token'+'=fixture-value','-----BEGIN '+'PRIVATE KEY-----']){
    const f=lifecycleFixture();f.binding.criteria[0].evidence=[value];assert.throws(()=>safeBinding(f.binding),e=>!e.message.includes(value)&&/sensitive/.test(e.message));
  }
  const f=lifecycleFixture();f.binding.criteria[0].evidence=['Published commit '+'a'.repeat(40)];assert.doesNotThrow(()=>safeBinding(f.binding));
});
test('mutation callbacks recheck closure evidence after a successful status comment',()=>{
  for(const reason of ['PR pushed','CI failed','Task changed','Base retargeted']){
    const f=remoteFixture();let calls=0;
    assert.throws(()=>applyIssue(f.api,'owner/repo',structuredClone(f.issue),{action:'close'},f.body,'bot',phase=>{calls++;if(phase==='close')throw Error(reason);}));
    assert.equal(calls,2);assert.equal(f.issue.state,'open');assert.equal(f.writes.length,1);
  }
});
test('PR dependencies bind repository and actual published plan ancestry',()=>{
  const current={number:4,base:{ref:'parent'}};
  const parent={number:3,head:{ref:'parent',repo:{full_name:'owner/repo'}},merged_at:null};
  assert.deepEqual(dependenciesFor([parent],current,'owner/repo',true),[3]);
  assert.deepEqual(dependenciesFor([{...parent,merged_at:'yesterday'}],current,'owner/repo',false),['BASE_NOT_ON_PUBLISHED_PLAN']);
  assert.deepEqual(dependenciesFor([{...parent,head:{ref:'parent',repo:{full_name:'fork/repo'}},merged_at:'yesterday'}],current,'owner/repo',false),['BASE_NOT_ON_PUBLISHED_PLAN']);
  assert.deepEqual(dependenciesFor([{...parent,merged_at:'yesterday'}],current,'owner/repo',true),[]);
});
test('lifecycle integration uses the PM transitive dependency blockers',()=>fixture(root=>{
  add(root,task('a',{dependencies:['b'],checks:[{command:'test',result:'passed',evidence:'Observed fixture'}]}));approve(root,'a');
  add(root,task('b',{dependencies:['c'],checks:[{command:'test',result:'passed',evidence:'Observed fixture'}]}));approve(root,'b');
  add(root,task('c',{openIssues:['Real runtime unavailable']}));
  const r=manager(root,ready(root));assert(r.priorities.find(t=>t.id==='a').blockers.includes('DEPENDENCY_PENDING:b'));
}));

test('publication rejects OAuth and signed query credentials, URL userinfo and standard bearer headers',()=>{
  const values=['https://example.invalid/proof?access_'+'token=fixture-value','https://user:fixture-value@example.invalid/proof','Bearer '+'fixture-value','https://example.invalid/proof?X-Amz-'+'Credential=fixture-value'];
  for(const value of values){const f=lifecycleFixture();f.binding.criteria[0].evidence=[value];let writes=0;assert.throws(()=>{safeBinding(f.binding);writes++;},e=>/sensitive/.test(e.message)&&!e.message.includes('fixture-value'));assert.equal(writes,0);}
});
