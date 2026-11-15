import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { setup,createTask,reviewTask,state,statePath,git,roles,suggestRoles } from '../chatdev/core.mjs';
import { manager } from '../chatdev/project-manager.mjs';
import { withReconciliation } from '../chatdev/pr-audit.mjs';

function fixture(fn){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-manager-'));
  try{
    const g=args=>execFileSync('git',args,{cwd:root,stdio:'pipe'});g(['init','-q']);g(['config','core.hooksPath',path.join(root,'.git/hooks')]);g(['config','user.name','Fixture']);g(['config','user.email','fixture@example.invalid']);
    fs.mkdirSync(path.join(root,'harness/chatdev'),{recursive:true});
    fs.copyFileSync(fileURLToPath(new URL('../../harness/chatdev/roles.json',import.meta.url)),path.join(root,'harness/chatdev/roles.json'));
    fs.writeFileSync(path.join(root,'harness/plan.json'),JSON.stringify({schemaVersion:1,branch:'main',remote:'origin',steps:[{id:1,title:'Accepted fixture'},{id:2,title:'Current fixture'},{id:3,title:'Later fixture'}]}));
    fs.writeFileSync(path.join(root,'harness/progress.json'),JSON.stringify({schemaVersion:1,activeStep:2,steps:[{id:1,status:'accepted'},{id:2,status:'active'},{id:3,status:'pending'}]}));
    g(['add','.']);g(['commit','-qm','Fixture']);setup(root);return fn(root);
  }finally{fs.rmSync(root,{recursive:true,force:true});}
}
const task=(id,extra={})=>({id,objective:'Resume approved tooling',step:2,scope:'tooling',author:'engineer-session',owner:'engineer',reviewers:['quality'],files:['tools/chatdev/example.mjs'],risks:[],dependencies:[],acceptance:['Focused checks pass'],checks:[],approvals:[],openIssues:[],nextAction:'Continue the existing tooling task',...extra});
const add=(root,t)=>createTask(root,t,state(root).revision);
const prBase={baseSha:'c'.repeat(40),baseRef:'main',baseRepository:'owner/repo'};
const ready=root=>({...prBase,baseTipSha:prBase.baseSha,head:git(root,['rev-parse','HEAD']),status:'READY',changes:[],blockers:[]});
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
import { issueDecision, applyIssue, bodyHash, reconcile } from '../chatdev/github-lifecycle.mjs';
const lifecycleFixture = () => {
  const issue={number:12,body:'Scoped package criterion',state:'open',updated_at:'v1'};
  const task={id:'package',acceptance:['Criterion'],checks:[{result:'passed'}],openIssues:[],approvals:[]};
  const binding={number:12,bodyHash:bodyHash(issue.body),taskId:task.id,criteria:[{text:'Criterion',result:'passed',evidence:['Actual observed result']}]};
  return {issue,task,binding,audit:{...prBase,baseTipSha:prBase.baseSha,status:'READY'}};
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

test('manager rejects legacy or inconsistent cached and supplied READY base identities',()=>fixture(root=>{
  const file=path.join(path.dirname(statePath(root)),'pr-audit-4.json');
  const legacy={head:ready(root).head,status:'READY',changes:[],blockers:[]};
  for(const record of [legacy,{...ready(root),baseSha:'invalid'}]){
    const supplied=manager(root,record);assert(supplied.blockers.includes('PR_AUDIT_BASE_UNBOUND'));assert.equal(supplied.prAudit.status,'BLOCKED');
    fs.writeFileSync(file,JSON.stringify({snapshot:{...prBase},result:record}));assert(manager(root).blockers.includes('PR_AUDIT_BASE_UNBOUND'));
  }
  for(const field of ['baseSha','baseRef','baseRepository']){
    const snapshot={...prBase,[field]:field==='baseSha'?'d'.repeat(40):'other'};
    fs.writeFileSync(file,JSON.stringify({snapshot,result:ready(root)}));assert(manager(root).blockers.includes('PR_AUDIT_BASE_UNBOUND'));
  }
  fs.writeFileSync(file,JSON.stringify({snapshot:{...prBase,baseTipSha:prBase.baseSha,complete:{baseTipStable:true}},result:ready(root)}));
  const bound=manager(root);assert.equal(bound.blockers.length,0);assert.equal(bound.prAudit.baseSha,prBase.baseSha);assert.match(bound.prAudit.source,/refresh before publication/);
}));
test('pure issue decision cannot close from a legacy READY without an actual-base identity',()=>{
  const f=lifecycleFixture();f.audit={status:'READY'};const result=decide(f);assert.equal(result.action,'keep-open');assert(result.blockers.includes('PR_BASE_UNBOUND'));
});

// Exercise the actual reconciliation write path. gh and ls-remote are isolated
// fixtures: no authenticated API or external Git destination is used.
function reconciliationFixture(fn){return fixture(root=>{
  add(root,task('package',{acceptance:['Criterion'],checks:[{command:'fixture',result:'passed',evidence:'Observed isolated check'}]}));approve(root,'package');
  const head=git(root,['rev-parse','HEAD']),branch=git(root,['branch','--show-current']);
  const originalPath=process.env.PATH, realGit=execFileSync('which',['git'],{encoding:'utf8'}).trim(),bin=path.join(root,'.git','fake-bin');fs.mkdirSync(bin);
  execFileSync(realGit,['remote','add','origin','https://github.com/owner/repo.git'],{cwd:root});
  fs.writeFileSync(path.join(bin,'gh'),"#!/usr/bin/env node\nif(process.argv[2]!=='repo')process.exit(91);process.stdout.write(JSON.stringify({nameWithOwner:'owner/repo'}));\n",{mode:0o755});
  fs.writeFileSync(path.join(bin,'git'),`#!/usr/bin/env node
const {spawnSync}=require('node:child_process'),a=process.argv.slice(2);
if(a[0]==='ls-remote'){if(a[1]!=='--heads'||a[2]!=='origin'||a[3]!==${JSON.stringify('refs/heads/'+branch)})process.exit(92);process.stdout.write(${JSON.stringify(head+'\trefs/heads/'+branch+'\n')});}
else {const r=spawnSync(${JSON.stringify(realGit)},a,{stdio:'inherit'});process.exit(r.status??1);}
`,{mode:0o755});
  process.env.PATH=bin+path.delimiter+originalPath;
  const issue={number:12,body:'Scoped package criterion',state:'open',updated_at:'v1'};
  const binding={number:12,bodyHash:bodyHash(issue.body),taskId:'package',criteria:[{text:'Criterion',result:'passed',evidence:['Observed reviewed result']}]};
  const current={number:4,state:'open',draft:false,head:{sha:head,ref:branch,repo:{full_name:'owner/repo'}},base:{sha:prBase.baseSha,ref:prBase.baseRef,repo:{full_name:prBase.baseRepository}}};
  const audit={...ready(root)},evidence={head,...prBase,githubIssues:[binding]};
  const baseTip={ref:'refs/heads/main',object:{type:'commit',sha:prBase.baseSha}};
  const writes=[],comments=[],refReads=[];let afterComment=null;
  const api=(endpoint,method='GET',data)=>{
    if(endpoint==='repos/owner/repo/git/ref/heads/main'){refReads.push(endpoint);return structuredClone(baseTip);}
    if(endpoint==='user')return{login:'fixture-bot'};
    if(endpoint.endsWith('/issues?state=open&per_page=100'))return[[structuredClone(issue)]];
    if(endpoint.endsWith('/pulls?state=all&per_page=100'))return[[structuredClone(current)]];
    if(endpoint.includes('/compare/'))return{status:'identical'};
    if(endpoint.endsWith('/pulls/4'))return structuredClone(current);
    if(endpoint.endsWith('/comments?per_page=100'))return[structuredClone(comments)];
    if(method==='POST'&&endpoint.endsWith('/issues/12/comments')){writes.push({method,endpoint});comments.push({id:21,user:{login:'fixture-bot'},body:data.body});issue.updated_at='v2';afterComment?.();return{};}
    if(method==='PATCH'&&endpoint.endsWith('/issues/12')){writes.push({method,endpoint});issue.state=data.state;return{};}
    if(method==='GET'&&endpoint.endsWith('/issues/12'))return structuredClone(issue);
    throw Error('Unexpected isolated API request');
  };
  try{return fn({root,current,baseTip,refReads,audit,evidence,writes,issue,comments,setAfterComment:callback=>afterComment=callback,run:(refresh=()=>structuredClone(audit),apply=true)=>reconcile(root,4,evidence,audit,apply,api,refresh)});}
  finally{process.env.PATH=originalPath;}
});}
test('actual reconcile writes are bound to a stable real PR base context',()=>reconciliationFixture(f=>{
  const before=fs.readFileSync(path.join(f.root,'harness/progress.json'),'utf8');
  const result=f.run();assert.equal(result.decisions[0].action,'close');assert.equal(f.issue.state,'closed');assert.deepEqual(f.writes.map(w=>w.method),['POST','PATCH']);
  assert.equal(result.baseSha,prBase.baseSha);assert.equal(result.baseTipSha,prBase.baseSha);assert.equal(f.refReads.length,3);assert.equal(result.pullRequests[0].base,'main');assert.equal(result.pullRequests[0].baseSha,prBase.baseSha);
  assert(f.comments[0].body.includes(prBase.baseSha));assert.equal(fs.readFileSync(path.join(f.root,'harness/progress.json'),'utf8'),before);
}));
test('actual reconcile rejects missing or stale audit/evidence bases before any mutation',()=>{
  for(const target of ['audit','evidence'])for(const field of ['baseSha','baseRef','baseRepository'])for(const missing of [false,true])reconciliationFixture(f=>{
    if(missing)delete f[target][field];else f[target][field]=field==='baseSha'?'d'.repeat(40):field==='baseRef'?'release':'foreign/repo';
    assert.throws(()=>f.run(),/HEAD\/base/);assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');
  });
});
test('actual reconcile detects base SHA, same-SHA retarget, and base repository races before commenting',()=>{
  for(const field of ['sha','ref','repository'])reconciliationFixture(f=>{
    const refresh=()=>{if(field==='repository')f.current.base.repo.full_name='foreign/repo';else f.current.base[field]=field==='sha'?'d'.repeat(40):'release';return structuredClone(f.audit);};
    assert.throws(()=>f.run(refresh),/Remote PR changed/);assert.equal(f.writes.length,0);
  });
});
test('actual reconcile compares every refreshed audit base field even when remote PR has not moved',()=>{
  for(const field of ['baseSha','baseRef','baseRepository'])reconciliationFixture(f=>{
    assert.throws(()=>f.run(()=>({...f.audit,[field]:field==='baseSha'?'d'.repeat(40):field==='baseRef'?'release':'foreign/repo'})),/Evidence changed/);assert.equal(f.writes.length,0);
  });
});
test('actual reconcile preserves a posted status comment but never closes after a base race',()=>{
  for(const field of ['sha','ref','repository'])reconciliationFixture(f=>{
    f.setAfterComment(()=>{if(field==='repository')f.current.base.repo.full_name='foreign/repo';else f.current.base[field]=field==='sha'?'d'.repeat(40):'release';});
    assert.throws(()=>f.run(),/Remote PR changed/);assert.deepEqual(f.writes.map(w=>w.method),['POST']);assert.equal(f.issue.state,'open');assert.equal(f.comments.length,1);
  });
});
test('read-only reconcile inventory remains available without mappings or supplied evidence',()=>reconciliationFixture(f=>{
  for(const key of Object.keys(f.evidence))delete f.evidence[key];f.audit.status='BLOCKED';
  const result=f.run(undefined,false);assert.equal(result.mode,'read-only');assert.equal(result.decisions.length,0);assert.equal(result.baseSha,prBase.baseSha);assert.equal(f.writes.length,0);
}));

test('PM rejects legacy READY without an independently collected base-tip proof',()=>fixture(root=>{
  const file=path.join(path.dirname(statePath(root)),'pr-audit-4.json');
  const current=ready(root),snapshot={...prBase,baseTipSha:prBase.baseSha,complete:{baseTipStable:true}};
  for(const mutate of [r=>delete r.baseTipSha,r=>r.baseTipSha='d'.repeat(40),r=>r.baseTipSha=[prBase.baseSha]]){
    const result=structuredClone(current);mutate(result);
    const supplied=manager(root,result);assert(supplied.blockers.includes('PR_AUDIT_BASE_TIP_UNBOUND'));assert.equal(supplied.prAudit.status,'BLOCKED');
    fs.writeFileSync(file,JSON.stringify({snapshot,result}));assert(manager(root).blockers.includes('PR_AUDIT_BASE_TIP_UNBOUND'));
  }
  for(const mutate of [s=>delete s.baseTipSha,s=>s.baseTipSha='d'.repeat(40),s=>delete s.complete.baseTipStable,s=>s.complete.baseTipStable=false]){
    const old=structuredClone(snapshot);mutate(old);fs.writeFileSync(file,JSON.stringify({snapshot:old,result:current}));
    const result=manager(root);assert(result.blockers.includes('PR_AUDIT_BASE_TIP_UNBOUND'));assert.equal(result.prAudit.status,'BLOCKED');
  }
  fs.writeFileSync(file,JSON.stringify({snapshot,result:current}));
  assert.equal(manager(root).blockers.length,0);assert.equal(manager(root).prAudit.baseTipSha,prBase.baseSha);
}));

test('pure issue decision cannot close from a base-bound legacy READY without tip proof',()=>{
  const f=lifecycleFixture();delete f.audit.baseTipSha;
  const result=decide(f);assert.equal(result.action,'keep-open');assert(result.blockers.includes('PR_BASE_TIP_UNBOUND'));
});

test('actual reconcile rejects stale or malformed current git refs despite stable PR metadata',()=>{
  for(const mutate of [f=>f.baseTip.object.sha='d'.repeat(40),f=>f.baseTip.ref='refs/heads/main-extra',f=>f.baseTip.object.type='tag',f=>delete f.baseTip.object,f=>delete f.baseTip.ref])reconciliationFixture(f=>{
    mutate(f);assert.throws(()=>f.run(),/PR base ref/);assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');
  });
  reconciliationFixture(f=>{delete f.audit.baseTipSha;assert.throws(()=>f.run(),/base-tip proof/);assert.equal(f.writes.length,0);});
});

test('actual reconcile independently rechecks base git ref immediately before commenting',()=>reconciliationFixture(f=>{
  assert.throws(()=>f.run(()=>{f.baseTip.object.sha='d'.repeat(40);return structuredClone(f.audit);}),/PR base ref/);
  assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');assert.equal(f.current.base.sha,prBase.baseSha);
}));

test('actual reconcile retains a posted comment but cannot close when only the git ref advances',()=>reconciliationFixture(f=>{
  f.setAfterComment(()=>{f.baseTip.object.sha='d'.repeat(40);});
  assert.throws(()=>f.run(),/PR base ref/);assert.deepEqual(f.writes.map(w=>w.method),['POST']);
  assert.equal(f.issue.state,'open');assert.equal(f.current.base.sha,prBase.baseSha);assert.equal(f.comments.length,1);
}));

test('actual reconcile includes base-tip proof in every refreshed audit comparison',()=>reconciliationFixture(f=>{
  assert.throws(()=>f.run(()=>({...f.audit,baseTipSha:'d'.repeat(40)})),/Evidence changed/);
  assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');
}));

test('stale or missing base refs preserve unmapped inventory and blocked PM coordination',()=>{
  for(const apply of [false,true])for(const missing of [false,true])reconciliationFixture(f=>{
    f.evidence.githubIssues=[];f.audit.status='BLOCKED';f.audit.blockers=['INCOMPLETE_BASETIPSTABLE'];
    if(missing)delete f.baseTip.object;else f.baseTip.object.sha='d'.repeat(40);
    const result=f.run(undefined,apply);
    assert.equal(result.baseTipSha,null);assert(result.dependencies.includes('BASE_REF_NOT_CURRENT'));
    assert.equal(result.decisions.length,0);assert.equal(result.openIssues[0],12);assert.equal(f.writes.length,0);
    const coordination=manager(f.root,{...f.audit,github:result});
    assert.equal(coordination.prAudit.status,'BLOCKED');assert(coordination.blockers.includes('INCOMPLETE_BASETIPSTABLE'));
    assert.equal(coordination.github.baseTipSha,null);
  });
});

test('mapped read-only previews cannot propose closure with stale or missing base ref proof',()=>{
  for(const mutate of [f=>f.baseTip.object.sha='d'.repeat(40),f=>delete f.baseTip.object,f=>delete f.audit.baseTipSha])reconciliationFixture(f=>{
    mutate(f);const result=f.run(undefined,false);
    assert.equal(result.baseTipSha,null);assert(result.dependencies.includes('BASE_REF_NOT_CURRENT'));
    assert.equal(result.decisions[0].action,'keep-open');assert(result.decisions[0].blockers.includes('PR_DEPENDENCY_UNMERGED'));
    assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');
  });
});

test('later reconciliation ref failure overrides initial READY in both live output and PM',()=>reconciliationFixture(f=>{
  assert.equal(f.audit.status,'READY');const historical=JSON.stringify(f.audit);
  f.evidence.githubIssues=[];f.baseTip.object.sha='d'.repeat(40);
  const github=f.run(undefined,false),live=withReconciliation(f.audit,github);
  assert.equal(github.baseTipSha,null);assert(github.dependencies.includes('BASE_REF_NOT_CURRENT'));
  assert.equal(live.status,'BLOCKED');assert(live.blockers.includes('BASE_REF_NOT_CURRENT'));
  const coordination=manager(f.root,{...f.audit,github});assert.equal(coordination.prAudit.status,'BLOCKED');assert(coordination.blockers.includes('BASE_REF_NOT_CURRENT'));
  assert.equal(manager(f.root,live).prAudit.status,'BLOCKED');assert.equal(JSON.stringify(f.audit),historical);assert.equal(f.writes.length,0);
  const failed=manager(f.root,{...f.audit,status:'CHANGES_REQUIRED',changes:['CI_FAILED'],github});
  assert.equal(failed.prAudit.status,'CHANGES_REQUIRED');assert(failed.blockers.includes('CI_FAILED'));
}));

test('actual CLI pipeline clamps a late ref-only race and preserves the stored collection snapshot',()=>reconciliationFixture(f=>{
  const file=path.join(f.root,'.git','cli-fixture.json'),counter=path.join(f.root,'.git','cli-ref-reads');
  fs.writeFileSync(file,JSON.stringify({current:f.current,issue:f.issue,counter}));
  const script=`#!${process.execPath}
const fs=require('node:fs'),a=process.argv.slice(2),x=JSON.parse(fs.readFileSync(${JSON.stringify(file)},'utf8'));let value;
if(a[0]==='repo')value={nameWithOwner:'owner/repo'};
else if(a.includes('POST')||a.includes('PATCH'))throw Error('No writes permitted in this fixture');
else if(a[1]==='graphql')value={data:{repository:{pullRequest:{reviewThreads:{nodes:[],pageInfo:{hasNextPage:false}}}}}};
else {const e=a.find(arg=>arg.startsWith('repos/'));
if(e==='repos/owner/repo/pulls/4')value={...x.current,mergeable:true,mergeable_state:'clean',user:{login:'fixture-author'}};
else if(e==='repos/owner/repo/git/ref/heads/main'){let n=0;try{n=Number(fs.readFileSync(x.counter,'utf8'));}catch{}fs.writeFileSync(x.counter,String(n+1));value={ref:'refs/heads/main',object:{type:'commit',sha:n<2?x.current.base.sha:'d'.repeat(40)}};}
else if(e.includes('/check-runs?'))value=[{check_runs:[{id:1,head_sha:x.current.head.sha,name:'contracts',app:{id:5,slug:'github-actions'},status:'completed',conclusion:'success'}]}];
else if(e.includes('/statuses?'))value=[[]];else if(e.includes('/status?'))value={sha:x.current.head.sha,state:'success',total_count:0};
else if(e.includes('/reviews?')||e.includes('/comments?'))value=[[]];
else if(e.endsWith('/issues?state=open&per_page=100'))value=[[x.issue]];
else if(e.endsWith('/pulls?state=all&per_page=100'))value=[[x.current]];
else if(e.includes('/compare/'))value={status:'identical'};else throw Error('Unexpected fixture endpoint');}
process.stdout.write(JSON.stringify(value));`;
  fs.writeFileSync(path.join(f.root,'.git','fake-bin','gh'),script,{mode:0o755});
  const head=f.audit.head,evidenceFile=path.join(f.root,'.git','cli-evidence.json');
  fs.writeFileSync(evidenceFile,JSON.stringify({head,...prBase,authorActor:'fixture-author',reviews:['quality','qa','security','architecture'].map(role=>({role,actor:'independent-'+role,head,...prBase,result:'passed',evidence:['Synthetic separate review']})),tests:[{name:'fixture',head,...prBase,result:'passed',evidence:['Synthetic check']}],githubIssues:f.evidence.githubIssues}));
  const cli=fileURLToPath(new URL('../chatdev/cli.mjs',import.meta.url));
  const live=JSON.parse(execFileSync(process.execPath,[cli,'github-sync','4',evidenceFile],{cwd:f.root,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
  assert.equal(live.status,'BLOCKED');assert.equal(live.baseTipSha,null);assert(live.blockers.includes('BASE_REF_NOT_CURRENT'));
  assert.equal(live.github.openIssues[0],12);assert.equal(live.github.baseTipSha,null);assert.equal(live.github.decisions[0].action,'keep-open');
  assert.equal(live.projectManagement.prAudit.status,'BLOCKED');assert(live.projectManagement.blockers.includes('BASE_REF_NOT_CURRENT'));
  const stored=JSON.parse(fs.readFileSync(path.join(path.dirname(statePath(f.root)),'pr-audit-4.json'),'utf8'));
  assert.equal(stored.result.status,'READY');assert.equal(stored.result.baseTipSha,prBase.baseSha);assert.equal(stored.snapshot.complete.baseTipStable,true);
  assert.equal(f.writes.length,0);assert.equal(f.issue.state,'open');
}));
