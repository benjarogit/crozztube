import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { assess, collect, audit, readBaseTip, withReconciliation } from '../chatdev/pr-audit.mjs';

const head='a'.repeat(40), old='b'.repeat(40);
const prBase={baseSha:'c'.repeat(40),baseRef:'main',baseRepository:'owner/repo'};
const hash=value=>createHash('sha256').update(value).digest('hex');
const snapshot=()=>({schemaVersion:2,baseSource:'github-base-branch-ref',repository:'owner/repo',...prBase,baseTipSha:prBase.baseSha,head,state:'open',draft:false,mergeable:true,complete:Object.fromEntries(['checks','statuses','reviews','issueComments','inlineComments','reviewThreads','headStable','baseStable','baseTipStable'].map(k=>[k,true])),checks:[{id:1,head,nameHash:hash('contracts'),appSlugHash:hash('github-actions'),status:'completed',conclusion:'success'}],statuses:[],combinedStatus:{head,state:'pending',total:0},reviews:[],comments:[],threads:[]});
const evidence=()=>({head,...prBase,authorActor:'author-session',requiredRoles:['quality','qa','security','architecture'],reviews:['quality','qa','security','architecture'].map(role=>({role,actor:role+'-review',head,...prBase,result:'passed',evidence:['actual separate review assertion']})),tests:[{name:'focused',head,...prBase,result:'passed',evidence:['17 fixture tests passed']}],commentDispositions:{},runtime:{required:true,verified:true,head,evidence:['runtime observation supplied by caller']}});
test('READY is advisory and requires current-head independent evidence',()=>{const r=assess(snapshot(),evidence());assert.equal(r.status,'READY');assert.match(r.permission,/Advisory/);assert.equal(assess(snapshot(),{}).status,'BLOCKED');});
test('known check, test and role failure require changes',()=>{for(const kind of ['ci','test','role']){const s=snapshot(),e=evidence();if(kind==='ci')s.checks[0].conclusion='failure';else if(kind==='test')e.tests[0].result='failed';else e.reviews[0].result='failed';assert.equal(assess(s,e).status,'CHANGES_REQUIRED');}});
test('queued checks and missing or unknown CI never become READY',()=>{for(const value of ['queued','in_progress']){const s=snapshot();s.checks[0].status=value;assert.equal(assess(s,evidence()).status,'BLOCKED');}const s=snapshot();s.checks=[];assert.equal(assess(s,evidence()).status,'BLOCKED');s.checks=[{head,status:'completed',conclusion:'unknown'}];assert.equal(assess(s,evidence()).status,'BLOCKED');});
test('neutral no-changes bot report is not an implicit approval',()=>{const s=snapshot();s.comments=[{id:'review:12',bodyHash:'neutral-body-hash'}];assert.equal(assess(s,evidence()).status,'BLOCKED');const e=evidence();e.commentDispositions['review:12']={head,...prBase,bodyHash:'neutral-body-hash',classification:'not-actionable',reason:'Read full report: no findings'};assert.equal(assess(s,e).status,'READY');});
test('old inline comments still require a disposition at current head',()=>{const s=snapshot();s.comments=[{id:'inline:9',commit:old,bodyHash:'v1'}];const e=evidence();e.commentDispositions['inline:9']={head:old,...prBase,bodyHash:'v1',classification:'addressed',reason:'Old response'};assert.equal(assess(s,e).status,'BLOCKED');e.commentDispositions['inline:9'].head=head;assert.equal(assess(s,e).status,'READY');s.comments[0].bodyHash='edited';assert.equal(assess(s,e).status,'BLOCKED');});
test('outstanding request survives later COMMENTED review, latest approval clears it',()=>{const s=snapshot();s.reviews=[{id:1,actorHash:'reviewer',state:'CHANGES_REQUESTED',head:old,submittedAt:'2026-01-01'},{id:2,actorHash:'reviewer',state:'COMMENTED',head,submittedAt:'2026-01-02'}];assert.equal(assess(s,evidence()).status,'BLOCKED');s.reviews.push({id:3,actorHash:'reviewer',state:'APPROVED',head,submittedAt:'2026-01-03'});assert.equal(assess(s,evidence()).status,'READY');});
test('all unresolved threads block even when comments were triaged',()=>{const s=snapshot();s.threads=[{id:'thread',resolved:false,comments:[]}];assert.equal(assess(s,evidence()).status,'BLOCKED');s.threads[0].resolved=true;assert.equal(assess(s,evidence()).status,'READY');});
test('every completeness flag and head binding is mandatory',()=>{for(const flag of Object.keys(snapshot().complete)){const s=snapshot();s.complete[flag]=false;assert.equal(assess(s,evidence()).status,'BLOCKED');}const e=evidence();e.head=old;assert.equal(assess(snapshot(),e).status,'BLOCKED');e.head=head;e.tests[0].head=old;assert.equal(assess(snapshot(),e).status,'BLOCKED');});
test('role evidence is not self-review, stale review, or whitespace evidence',()=>{for(const change of ['self','old','blank']){const e=evidence();if(change==='self')e.reviews[0].actor=e.authorActor;if(change==='old')e.reviews[0].head=old;if(change==='blank')e.reviews[0].evidence=[' '];assert.equal(assess(snapshot(),e).status,'BLOCKED');}});
test('latest status per context supersedes historical failures',()=>{const s=snapshot();s.statuses=[{id:1,key:'ci',head,state:'failure',createdAt:'2026-01-01'},{id:2,key:'ci',head,state:'success',createdAt:'2026-01-02'}];s.combinedStatus={head,total:1,state:'success'};assert.equal(assess(s,evidence()).status,'READY');s.combinedStatus.state='pending';assert.equal(assess(s,evidence()).status,'BLOCKED');});
test('unavailable editor does not block a valid product PR or become a runtime pass',()=>{const e=evidence();delete e.runtime;const r=assess(snapshot(),e);assert.equal(r.status,'READY');assert.deepEqual(r.editorIntegration,{owner:'Orqen',status:'not-verified',blocking:false});});
test('missing or malformed structures fail closed',()=>{assert.equal(assess(null,null).status,'BLOCKED');assert.equal(assess({...snapshot(),checks:{}},{...evidence(),reviews:{}}).status,'BLOCKED');assert.equal(assess({...snapshot(),mergeable:null},evidence()).status,'BLOCKED');assert.equal(assess({...snapshot(),mergeable:false},evidence()).status,'CHANGES_REQUIRED');});

test('collector uses paginated fixed argv; persisted audit never includes raw bodies or URL tokens',()=>{
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-pr-test-')), prior=process.env.PATH;
  try{
    execFileSync('git',['init','-q',root]);const bin=path.join(root,'bin');fs.mkdirSync(bin);
    const stub=`#!/usr/bin/env node
const fs=require('node:fs');const a=process.argv.slice(2);const h='a'.repeat(40);let result;
if(a[0]==='repo')result={nameWithOwner:'owner/repo'};
else if(a[1]==='graphql'){result={data:{repository:{pullRequest:{reviewThreads:{nodes:[],pageInfo:{hasNextPage:false,endCursor:null}}}}}};}
else {const endpoint=a.at(-1);const paged=a.includes('--paginate')&&a.includes('--slurp');
if(endpoint==='repos/owner/repo/git/ref/heads/main')result={ref:'refs/heads/main',object:{type:'commit',sha:'c'.repeat(40)}};
else if(endpoint.endsWith('/pulls/7'))result={head:{sha:h},base:{sha:'c'.repeat(40),ref:'main',repo:{full_name:'owner/repo'}},state:'open',draft:false,mergeable:true,mergeable_state:'clean',user:{login:'owner'},html_url:'https://github.com/owner/repo/pull/7?secret=TOKEN'};
else if(endpoint.includes('/check-runs?')){if(!paged)process.exit(4);result=[{check_runs:[{id:1,head_sha:h,app:{id:5,slug:'github-actions'},name:'contracts',status:'completed',conclusion:'success',html_url:'https://github.com/owner/repo/actions/runs/1?token=TOKEN'}]}];}
else if(endpoint.includes('/statuses?')){if(!paged)process.exit(4);result=[[]];}
else if(endpoint.includes('/status?'))result={sha:h,state:'pending',total_count:0};
else if(endpoint.includes('/reviews?')){if(!paged)process.exit(4);result=[[{id:9,body:'PRIVATE_RAW_BOT_BODY',state:'COMMENTED',commit_id:h,user:{login:'bot'},submitted_at:'2026-01-01',html_url:'https://github.com/owner/repo/pull/7#pullrequestreview-9'}]];}
else if(endpoint.includes('/comments?')){if(!paged)process.exit(4);result=[[],[]];}else process.exit(4);}
process.stdout.write(JSON.stringify(result));`;
    fs.writeFileSync(path.join(bin,'gh'),stub,{mode:0o755});process.env.PATH=bin+path.delimiter+prior;
    const s=collect(root,7);assert(Object.values(s.complete).every(Boolean));assert.equal(s.comments.length,1);assert(!JSON.stringify(s).includes('PRIVATE_RAW_BOT_BODY'));assert(!JSON.stringify(s).includes('TOKEN'));
    const result=audit(root,7);assert.equal(result.status,'BLOCKED');const local=execFileSync('git',['rev-parse','--git-path','crozztube-chatdev/pr-audit-7.json'],{cwd:root,encoding:'utf8'}).trim();const saved=fs.readFileSync(path.resolve(root,local),'utf8');assert(!saved.includes('PRIVATE_RAW_BOT_BODY'));assert(!saved.includes('TOKEN'));assert.equal(fs.statSync(path.resolve(root,local)).mode&0o777,0o600);
  }finally{process.env.PATH=prior;fs.rmSync(root,{recursive:true,force:true});}
});

test('editor flags and simulated or stale execution never fabricate Orqen verification',()=>{for(const runtime of [undefined,{required:false},{verified:true,head,evidence:['simulated fixture']},{verified:true,head:old,evidence:['old client observation']}]){const e=evidence();e.runtime=runtime;const r=assess(snapshot(),e);assert.equal(r.status,'READY');assert.equal(r.editorIntegration.status,'not-verified');}});
test('blank product test evidence still blocks irrespective of editor flags',()=>{const e=evidence();e.tests[0].evidence=[' '];assert.equal(assess(snapshot(),e).status,'BLOCKED');e.tests=evidence().tests;e.runtime.evidence=[''];const r=assess(snapshot(),e);assert.equal(r.status,'READY');assert.equal(r.editorIntegration.status,'not-verified');});
test('GitHub blocked merge requirements cannot be overridden by role evidence',()=>{const s=snapshot();s.mergeableState='blocked';assert.equal(assess(s,evidence()).status,'BLOCKED');});

test('bot success cannot replace required contracts when absent, skipped or neutral',()=>{for(const conclusion of [null,'skipped','neutral']){const s=snapshot();const bot={id:2,head,nameHash:hash('CodeRabbit'),appSlugHash:hash('coderabbitai'),status:'completed',conclusion:'success'};s.checks=conclusion?[{...s.checks[0],conclusion},bot]:[bot];assert.equal(assess(s,evidence()).status,'BLOCKED');assert(assess(s,evidence()).blockers.includes('REQUIRED_CONTRACTS_NOT_PASSED'));}const s=snapshot();s.checks[0].appSlugHash=hash('different-app');assert.equal(assess(s,evidence()).status,'BLOCKED');});

test('editor separation never bypasses independent reviews, CI, comments or exact-head evidence',()=>{
  for(const mutate of [e=>e.reviews=[],e=>e.tests[0].head=old,e=>e.tests[0].result='failed']){const e=evidence();delete e.runtime;mutate(e);assert.notEqual(assess(snapshot(),e).status,'READY');}
  const s=snapshot();s.comments=[{id:'inline:123',bodyHash:'pending'}];const e=evidence();delete e.runtime;assert.notEqual(assess(s,e).status,'READY');s.comments=[];s.checks[0].conclusion='failure';assert.equal(assess(s,e).status,'CHANGES_REQUIRED');
});

test('actual PR base is mandatory in snapshots and every local evidence context',()=>{
  for(const field of ['baseSha','baseRef','baseRepository']){
    const missing=snapshot();delete missing[field];assert.equal(assess(missing,evidence()).status,'BLOCKED');
    for(const location of ['top','role','test','disposition'])for(const absent of [true,false]){
      const s=snapshot(),e=evidence();s.comments=[{id:'issue:1',bodyHash:'body'}];
      e.commentDispositions['issue:1']={...prBase,head,bodyHash:'body',classification:'not-actionable',reason:'Reviewed whole comment'};
      const target=location==='top'?e:location==='role'?e.reviews[0]:location==='test'?e.tests[0]:e.commentDispositions['issue:1'];
      if(absent)delete target[field];else target[field]=field==='baseSha'?old:field==='baseRef'?'release':'foreign/repo';
      assert.equal(assess(s,e).status,'BLOCKED',location+': '+field);
    }
  }
  const invalid=snapshot();invalid.baseSha='x'.repeat(40);assert.equal(assess(invalid,evidence()).status,'BLOCKED');
  for(const field of ['baseSha','baseRef','baseRepository']){const malformed=snapshot();malformed[field]=[prBase[field]];assert.equal(assess(malformed,evidence()).status,'BLOCKED');}
  const legacy=evidence();legacy.base=legacy.baseSha;delete legacy.baseSha;assert.equal(assess(snapshot(),legacy).status,'BLOCKED');
  const incomplete=snapshot();delete incomplete.complete.baseStable;assert.equal(assess(incomplete,evidence()).status,'BLOCKED');
  const result=assess(snapshot(),evidence());assert.equal(result.status,'READY');
  for(const [key,value]of Object.entries(prBase))assert.equal(result[key],value);
});

function collectionFixture(change,fn){
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'chatdev-base-collect-')),prior=process.env.PATH;
  try{
    execFileSync('git',['init','-q',root]);const bin=path.join(root,'bin');fs.mkdirSync(bin);
    const stub=`#!/usr/bin/env node
const fs=require('node:fs'),path=require('node:path'),a=process.argv.slice(2),h='a'.repeat(40),change=${JSON.stringify(change)};let result;
if(a[0]==='repo')result={nameWithOwner:'owner/repo'};
else if(a[1]==='graphql')result={data:{repository:{pullRequest:{reviewThreads:{nodes:[],pageInfo:{hasNextPage:false}}}}}};
else {const e=a.at(-1);fs.appendFileSync(path.join(process.cwd(),'.git','api-reads'),e+'\\n');
if(e==='repos/owner/repo/git/ref/heads/main'){const file=path.join(process.cwd(),'.git','tip-reads');let n=0;try{n=Number(fs.readFileSync(file,'utf8'));}catch{}fs.writeFileSync(file,String(n+1));
if(change==='tip-missing'||n&&change==='tip-missing-final')process.exit(4);
result={ref:change==='tip-wrong-ref'?'refs/heads/main-extra':'refs/heads/main',object:{type:change==='tip-tag'?'tag':'commit',sha:change==='tip-stale'||change==='static-base-current-tip'||n&&change==='tip-moves'||!n&&change==='tip-recovers'?'d'.repeat(40):change==='tip-invalid-sha'?'x'.repeat(40):'c'.repeat(40)}};
if(change==='tip-array')result=[result];if(change==='tip-no-object')delete result.object;}
else if(e.endsWith('/pulls/7')){const file=path.join(process.cwd(),'.git','base-reads');let n=0;try{n=Number(fs.readFileSync(file,'utf8'));}catch{}fs.writeFileSync(file,String(n+1));const base={sha:'c'.repeat(40),ref:'main',repo:{full_name:'owner/repo'}};if(change==='foreign-initial')base.repo.full_name='foreign/repo';if(n){if(change==='sha')base.sha='d'.repeat(40);if(change==='ref')base.ref='release';if(change==='repository')base.repo.full_name='foreign/repo';}result={head:{sha:n&&change==='head'?'b'.repeat(40):h},base:change==='missing'?undefined:base,state:'open',draft:false,mergeable:true,mergeable_state:'clean',user:{login:'owner'}};}
else if(e.includes('/check-runs?'))result=[{check_runs:[{id:1,head_sha:h,name:'contracts',app:{id:5,slug:'github-actions'},status:'completed',conclusion:'success'}]}];
else if(e.includes('/statuses?'))result=[[]];else if(e.includes('/status?'))result={sha:h,state:'success',total_count:0};else if(e.includes('/reviews?')||e.includes('/comments?'))result=[[]];else process.exit(4);}
process.stdout.write(JSON.stringify(result));`;
    fs.writeFileSync(path.join(bin,'gh'),stub,{mode:0o755});process.env.PATH=bin+path.delimiter+prior;
    return fn(root);
  }finally{process.env.PATH=prior;fs.rmSync(root,{recursive:true,force:true});}
}
test('collector binds stable head/base/ref/repository and never invents a CI base attestation',()=>{
  collectionFixture('none',root=>{const s=collect(root,7);assert(Object.values(s.complete).every(Boolean));assert.equal(assess(s,evidence()).status,'READY');for(const [k,v]of Object.entries(prBase))assert.equal(s[k],v);assert.equal(s.checks[0].baseSha,undefined);assert.equal(s.baseTipSha,prBase.baseSha);const requests=fs.readFileSync(path.join(root,'.git','api-reads'),'utf8').trim().split('\n');assert.equal(requests[1],'repos/owner/repo/git/ref/heads/main');assert.equal(requests.at(-1),'repos/owner/repo/git/ref/heads/main');assert.equal(requests.filter(e=>e.includes('/git/ref/')).length,2);});
  for(const change of ['ref','repository','head'])collectionFixture(change,root=>{const s=collect(root,7);assert.equal(assess(s,evidence()).status,'BLOCKED');assert.equal(s.complete.headStable,false);if(change!=='head')assert.equal(s.complete.baseStable,false);});
  for(const change of ['missing','foreign-initial'])collectionFixture(change,root=>assert.throws(()=>collect(root,7),/Missing or foreign PR base/));
});

test('historical pull-base SHA is diagnostic while current ref binds every local review and test',()=>{
  collectionFixture('static-base-current-tip',root=>{
    const s=collect(root,7),current='d'.repeat(40),e=evidence();
    e.baseSha=current;for(const r of [...e.reviews,...e.tests])r.baseSha=current;
    assert.equal(s.schemaVersion,2);assert.equal(s.baseSource,'github-base-branch-ref');
    assert.equal(s.reportedBaseSha,prBase.baseSha);assert.equal(s.baseSha,current);assert.equal(s.baseTipSha,current);
    assert(Object.values(s.complete).every(Boolean));assert.equal(assess(s,e).status,'READY');
    // Head-bound hosted contracts remain green; old-target local assertions
    // cannot be promoted to proof that this current target was tested.
    assert.equal(s.checks[0].head,head);assert.equal(assess(s,evidence()).status,'BLOCKED');
    for(const target of [e.reviews[0],e.tests[0]]){target.baseSha=prBase.baseSha;assert.equal(assess(s,e).status,'BLOCKED');target.baseSha=current;}
    s.comments=[{id:'issue:1',bodyHash:'comment'}];
    e.commentDispositions['issue:1']={head,...prBase,baseSha:current,bodyHash:'comment',classification:'addressed',reason:'Reviewed against the actual current target'};
    assert.equal(assess(s,e).status,'READY');e.commentDispositions['issue:1'].baseSha=prBase.baseSha;
    assert.equal(assess(s,e).status,'BLOCKED');
  });
  collectionFixture('sha',root=>{
    const s=collect(root,7);assert.equal(s.reportedBaseSha,'d'.repeat(40));assert.equal(s.baseSha,prBase.baseSha);
    assert.equal(assess(s,evidence()).status,'READY');
  });
});

test('a current exact commit ref is mandatory even when PR metadata and head stay unchanged',()=>{
  for(const change of ['tip-moves','tip-recovers','tip-missing','tip-missing-final','tip-wrong-ref','tip-tag','tip-array','tip-no-object','tip-invalid-sha'])collectionFixture(change,root=>{
    const s=collect(root,7),result=assess(s,evidence());
    assert.equal(s.complete.headStable,true,change);
    assert.equal(s.complete.baseStable,true,change);
    assert.equal(s.complete.baseTipStable,false,change);
    assert.equal(result.status,'BLOCKED',change);
    assert(result.blockers.includes('INCOMPLETE_BASETIPSTABLE'),change);
    assert.equal(result.baseTipSha,s.baseTipSha);
  });
});

test('exact ref validation rejects prefix matches, non-commit objects and malformed identities',()=>{
  const identity={...prBase,baseRef:'codex/parent with space'};
  assert.throws(()=>readBaseTip(identity,()=>{throw Error('must not request');}),/Invalid PR base/);
  const target={...prBase,baseRef:'codex/parent'},payload={ref:'refs/heads/codex/parent',object:{type:'commit',sha:prBase.baseSha}};
  let requested;
  assert.equal(readBaseTip(target,endpoint=>{requested=endpoint;return payload;}),prBase.baseSha);
  assert.equal(requested,'repos/owner/repo/git/ref/heads/codex%2Fparent');
  assert.equal(readBaseTip(target,()=>({...payload,object:{type:'commit',sha:old}})),old);
  for(const response of [null,[],[payload],{...payload,ref:'heads/codex/parent'},{...payload,ref:payload.ref+'-extra'},{...payload,ref:'refs/tags/codex/parent'},{...payload,object:{type:'tag',sha:prBase.baseSha}},{...payload,object:{sha:prBase.baseSha}},{...payload,object:{type:'commit',sha:[prBase.baseSha]}}]){
    assert.throws(()=>readBaseTip(target,()=>response),/missing, changed or inconsistent/);
  }
});

test('legacy or mismatched base-tip snapshots never assess READY or alter role evidence requirements',()=>{
  for(const mutate of [s=>delete s.baseTipSha,s=>s.baseTipSha=old,s=>delete s.complete.baseTipStable,s=>s.complete.baseTipStable=false,s=>s.schemaVersion=1,s=>delete s.baseSource,s=>s.baseSource='historical-pull-base']){
    const s=snapshot();mutate(s);assert.equal(assess(s,evidence()).status,'BLOCKED');
  }
  const e=evidence(),result=assess(snapshot(),e);
  assert.equal(result.status,'READY');assert.equal(result.baseTipSha,prBase.baseSha);
  assert.equal(e.baseTipSha,undefined);assert.equal(e.reviews[0].baseTipSha,undefined);assert.equal(e.tests[0].baseTipSha,undefined);
});

test('fresh reconciliation can only lower live readiness and never mutates the original audit',()=>{
  const original=assess(snapshot(),evidence()),before=JSON.stringify(original);
  const valid={schemaVersion:2,baseSource:'github-base-branch-ref',head,...prBase,baseTipSha:prBase.baseSha,dependencies:[]};
  assert.equal(withReconciliation(original,valid).status,'READY');
  for(const github of [null,{...valid,baseTipSha:null},{...valid,dependencies:['BASE_REF_NOT_CURRENT']},{...valid,baseSha:old},{...valid,head:old},{...valid,dependencies:undefined}]){
    const result=withReconciliation(original,github);assert.equal(result.status,'BLOCKED');assert.equal(result.baseTipSha,null);assert(result.blockers.includes('BASE_REF_NOT_CURRENT'));
    const failed=withReconciliation({...original,status:'CHANGES_REQUIRED',changes:['CI_FAILED']},github);
    assert.equal(failed.status,'CHANGES_REQUIRED');assert.deepEqual(failed.changes,['CI_FAILED']);
  }
  assert.equal(JSON.stringify(original),before);
  assert.equal(withReconciliation({...original,status:'BLOCKED'},valid).status,'BLOCKED');
});
