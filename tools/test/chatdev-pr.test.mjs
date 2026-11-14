import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { assess, collect, audit } from '../chatdev/pr-audit.mjs';

const head='a'.repeat(40), old='b'.repeat(40);
const hash=value=>createHash('sha256').update(value).digest('hex');
const snapshot=()=>({schemaVersion:1,head,state:'open',draft:false,mergeable:true,complete:Object.fromEntries(['checks','statuses','reviews','issueComments','inlineComments','reviewThreads','headStable'].map(k=>[k,true])),checks:[{id:1,head,nameHash:hash('contracts'),appSlugHash:hash('github-actions'),status:'completed',conclusion:'success'}],statuses:[],combinedStatus:{head,state:'pending',total:0},reviews:[],comments:[],threads:[]});
const evidence=()=>({head,authorActor:'author-session',requiredRoles:['quality','qa','security','architecture'],reviews:['quality','qa','security','architecture'].map(role=>({role,actor:role+'-review',head,result:'passed',evidence:['actual separate review assertion']})),tests:[{name:'focused',head,result:'passed',evidence:['17 fixture tests passed']}],commentDispositions:{},runtime:{required:true,verified:true,head,evidence:['runtime observation supplied by caller']}});
test('READY is advisory and requires current-head independent evidence',()=>{const r=assess(snapshot(),evidence());assert.equal(r.status,'READY');assert.match(r.permission,/Advisory/);assert.equal(assess(snapshot(),{}).status,'BLOCKED');});
test('known check, test and role failure require changes',()=>{for(const kind of ['ci','test','role']){const s=snapshot(),e=evidence();if(kind==='ci')s.checks[0].conclusion='failure';else if(kind==='test')e.tests[0].result='failed';else e.reviews[0].result='failed';assert.equal(assess(s,e).status,'CHANGES_REQUIRED');}});
test('queued checks and missing or unknown CI never become READY',()=>{for(const value of ['queued','in_progress']){const s=snapshot();s.checks[0].status=value;assert.equal(assess(s,evidence()).status,'BLOCKED');}const s=snapshot();s.checks=[];assert.equal(assess(s,evidence()).status,'BLOCKED');s.checks=[{head,status:'completed',conclusion:'unknown'}];assert.equal(assess(s,evidence()).status,'BLOCKED');});
test('neutral no-changes bot report is not an implicit approval',()=>{const s=snapshot();s.comments=[{id:'review:12',bodyHash:'neutral-body-hash'}];assert.equal(assess(s,evidence()).status,'BLOCKED');const e=evidence();e.commentDispositions['review:12']={head,bodyHash:'neutral-body-hash',classification:'not-actionable',reason:'Read full report: no findings'};assert.equal(assess(s,e).status,'READY');});
test('old inline comments still require a disposition at current head',()=>{const s=snapshot();s.comments=[{id:'inline:9',commit:old,bodyHash:'v1'}];const e=evidence();e.commentDispositions['inline:9']={head:old,bodyHash:'v1',classification:'addressed',reason:'Old response'};assert.equal(assess(s,e).status,'BLOCKED');e.commentDispositions['inline:9'].head=head;assert.equal(assess(s,e).status,'READY');s.comments[0].bodyHash='edited';assert.equal(assess(s,e).status,'BLOCKED');});
test('outstanding request survives later COMMENTED review, latest approval clears it',()=>{const s=snapshot();s.reviews=[{id:1,actorHash:'reviewer',state:'CHANGES_REQUESTED',head:old,submittedAt:'2026-01-01'},{id:2,actorHash:'reviewer',state:'COMMENTED',head,submittedAt:'2026-01-02'}];assert.equal(assess(s,evidence()).status,'BLOCKED');s.reviews.push({id:3,actorHash:'reviewer',state:'APPROVED',head,submittedAt:'2026-01-03'});assert.equal(assess(s,evidence()).status,'READY');});
test('all unresolved threads block even when comments were triaged',()=>{const s=snapshot();s.threads=[{id:'thread',resolved:false,comments:[]}];assert.equal(assess(s,evidence()).status,'BLOCKED');s.threads[0].resolved=true;assert.equal(assess(s,evidence()).status,'READY');});
test('every completeness flag and head binding is mandatory',()=>{for(const flag of Object.keys(snapshot().complete)){const s=snapshot();s.complete[flag]=false;assert.equal(assess(s,evidence()).status,'BLOCKED');}const e=evidence();e.head=old;assert.equal(assess(snapshot(),e).status,'BLOCKED');e.head=head;e.tests[0].head=old;assert.equal(assess(snapshot(),e).status,'BLOCKED');});
test('role evidence is not self-review, stale review, or whitespace evidence',()=>{for(const change of ['self','old','blank']){const e=evidence();if(change==='self')e.reviews[0].actor=e.authorActor;if(change==='old')e.reviews[0].head=old;if(change==='blank')e.reviews[0].evidence=[' '];assert.equal(assess(snapshot(),e).status,'BLOCKED');}});
test('latest status per context supersedes historical failures',()=>{const s=snapshot();s.statuses=[{id:1,key:'ci',head,state:'failure',createdAt:'2026-01-01'},{id:2,key:'ci',head,state:'success',createdAt:'2026-01-02'}];s.combinedStatus={head,total:1,state:'success'};assert.equal(assess(s,evidence()).status,'READY');s.combinedStatus.state='pending';assert.equal(assess(s,evidence()).status,'BLOCKED');});
test('runtime prerequisite defaults required and cannot be inferred from fixtures',()=>{const e=evidence();delete e.runtime;assert(assess(snapshot(),e).blockers.includes('RUNTIME_PREREQUISITE_UNVERIFIED'));});
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
if(endpoint.endsWith('/pulls/7'))result={head:{sha:h},state:'open',draft:false,mergeable:true,mergeable_state:'clean',user:{login:'owner'},html_url:'https://github.com/owner/repo/pull/7?secret=TOKEN'};
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

test('local evidence cannot waive cross-client runtime prerequisite',()=>{const e=evidence();e.runtime.required=false;assert.equal(assess(snapshot(),e).status,'BLOCKED');assert(assess(snapshot(),e).blockers.includes('RUNTIME_PREREQUISITE_CANNOT_BE_WAIVED'));});
test('blank test and runtime evidence remains unverified',()=>{const e=evidence();e.tests[0].evidence=[' '];assert.equal(assess(snapshot(),e).status,'BLOCKED');e.tests=evidence().tests;e.runtime.evidence=[''];assert.equal(assess(snapshot(),e).status,'BLOCKED');});
test('GitHub blocked merge requirements cannot be overridden by role evidence',()=>{const s=snapshot();s.mergeableState='blocked';assert.equal(assess(s,evidence()).status,'BLOCKED');});

test('bot success cannot replace required contracts when absent, skipped or neutral',()=>{for(const conclusion of [null,'skipped','neutral']){const s=snapshot();const bot={id:2,head,nameHash:hash('CodeRabbit'),appSlugHash:hash('coderabbitai'),status:'completed',conclusion:'success'};s.checks=conclusion?[{...s.checks[0],conclusion},bot]:[bot];assert.equal(assess(s,evidence()).status,'BLOCKED');assert(assess(s,evidence()).blockers.includes('REQUIRED_CONTRACTS_NOT_PASSED'));}const s=snapshot();s.checks[0].appSlugHash=hash('different-app');assert.equal(assess(s,evidence()).status,'BLOCKED');});
