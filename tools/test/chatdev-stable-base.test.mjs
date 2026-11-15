import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { selectStableBase, acceptStableRuntime, inspectStableRuntime, acceptDocumentationReview } from '../chatdev/stable-series-base.mjs';
import { assess } from '../chatdev/pr-audit.mjs';
import { digest, indexDigest, localPath } from '../harness/workflow.mjs';

// Every repository, remote, check/review and private receipt below is SYNTHETIC.
// These tests never create or modify a real project's publication/approval.
const git=(root,...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trimEnd();
const hash=x=>createHash('sha256').update(x).digest('hex');
const canonical=x=>JSON.stringify(x&&typeof x==='object'?Array.isArray(x)?x.map(v=>JSON.parse(canonical(v))):Object.fromEntries(Object.keys(x).sort().map(k=>[k,JSON.parse(canonical(x[k]))])):x);
const write=(root,file,value)=>{const target=path.join(root,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,typeof value==='string'?value:JSON.stringify(value));return target;};
const privateJson=(root,name,value)=>{const file=localPath(root,name);fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});fs.writeFileSync(file,JSON.stringify(value)+'\n',{mode:0o600});fs.chmodSync(file,0o600);return file;};
const read=file=>JSON.parse(fs.readFileSync(file,'utf8'));
function fixture(fn,{pinOutsideRuntime=false}={}){
  const home=fs.mkdtempSync(path.join(os.tmpdir(),'SYNTHETIC-stable-series-')),admin=path.join(home,'admin'),bare=path.join(home,'origin.git'),runtime=path.join(home,'runtime'),feature=path.join(home,'feature');
  fs.mkdirSync(admin);
  try{
    git(home,'init','--bare',bare);git(admin,'init','-b','main');git(admin,'config','user.name','Synthetic fixture');git(admin,'config','user.email','fixture@example.invalid');git(admin,'config','core.hooksPath',path.join(home,'unused-hooks'));git(admin,'remote','add','origin',bare);
    write(admin,'README.md','Synthetic initial main\n');write(admin,'harness/progress.json',{schemaVersion:1,activeStep:8,steps:[]});git(admin,'add','.');git(admin,'commit','-qm','Synthetic initial');const main=git(admin,'rev-parse','HEAD');git(admin,'push','-u','origin','main');
    git(admin,'checkout','-b','codex/chatdev-integration');write(admin,'tools/runtime.mjs','// Synthetic tooling parent\n');git(admin,'add','.');git(admin,'commit','-qm','Synthetic tooling base');const base=git(admin,'rev-parse','HEAD');
    git(admin,'checkout','-b','codex/series-seasons',main);write(admin,'feature.txt','Synthetic feature\n');git(admin,'add','.');git(admin,'commit','-qm','Synthetic feature');const featureHead=git(admin,'rev-parse','HEAD');git(admin,'merge','--no-ff','--no-edit','codex/chatdev-integration');const merge=git(admin,'rev-parse','HEAD');git(admin,'push','-u','origin','codex/series-seasons');
    git(admin,'checkout','codex/chatdev-integration');
    const policy={schemaVersion:1,repository:'benjarogit/crozztube',toolingBranch:'codex/chatdev-integration',featureBranch:'codex/series-seasons',featureBase:pinOutsideRuntime?featureHead:base,featureMerge:merge,runtimePr:3,auditBaseBranch:'main',requiredChecks:['contracts'],revokedRuntimeHeads:[],revokedFeatureBases:[]};
    write(admin,'harness/chatdev/feature-bases.json',policy);write(admin,'tools/runtime.mjs','// Synthetic reviewed runtime\n');git(admin,'add','.');git(admin,'commit','-qm','Synthetic runtime release');git(admin,'push','-u','origin','codex/chatdev-integration');
    git(home,'clone','--quiet','--branch','codex/chatdev-integration',bare,runtime);git(home,'clone','--quiet','--branch','codex/series-seasons',bare,feature);
    const head=git(runtime,'rev-parse','HEAD'),remoteIdentity=hash('fetch\0'+git(runtime,'remote','get-url','--all','origin')+'\0push\0'+git(runtime,'remote','get-url','--push','--all','origin'));
    const checkedAt='2026-01-01T00:00:00.000Z',sourceDigest=digest(runtime),index=indexDigest(runtime);
    const verification={branch:policy.toolingBranch,base:main,digest:sourceDigest,indexDigest:index,checkedAt,purpose:'chatdev-tooling-only'},publication={branch:policy.toolingBranch,commit:head,base:main,checkedAt};
    const verificationFile=privateJson(runtime,'chatdev-verification.json',verification),publicationFile=privateJson(runtime,'chatdev-publication.json',publication);
    const target={baseSha:main,baseRef:'main',baseRepository:policy.repository};
    const snapshot={schemaVersion:2,baseSource:'github-base-branch-ref',reportedBaseSha:main,repository:policy.repository,pr:3,head,...target,baseTipSha:main,state:'open',draft:false,mergeable:true,mergeableState:'clean',complete:Object.fromEntries(['checks','statuses','reviews','issueComments','inlineComments','reviewThreads','headStable','baseStable','baseTipStable'].map(k=>[k,true])),checks:[{head,status:'completed',conclusion:'success',nameHash:hash('contracts'),appSlugHash:hash('github-actions')}],statuses:[],reviews:[],comments:[],threads:[],combinedStatus:{head,total:0,state:'pending'}};
    const evidence={head,...target,sourceDigest,authorActor:'synthetic-author',reviews:['quality','qa','security','architecture'].map(role=>({role,actor:'synthetic-independent-'+role,head,...target,sourceDigest,result:'passed',evidence:['Synthetic fixture only']})),tests:[{name:'Synthetic test',head,...target,sourceDigest,result:'passed',evidence:['Synthetic fixture only']}],commentDispositions:{}};
    const result=assess(snapshot,evidence);assert.equal(result.status,'READY');
    const audit={snapshot,result,evidence,base:main},auditFile=privateJson(runtime,'stable-series-runtime-audit.json',audit);
    const release={schemaVersion:1,purpose:'approved-external-series-runtime',repository:policy.repository,runtime:head,policyDigest:hash(canonical(policy)),remoteIdentity,sourceDigest,indexDigest:index,verificationDigest:hash(fs.readFileSync(verificationFile)),publicationDigest:hash(fs.readFileSync(publicationFile)),base:main,checkedAt,auditHead:head,auditBase:main,auditDigest:hash(fs.readFileSync(auditFile)),approvedAt:checkedAt};
    const releaseFile=privateJson(runtime,'stable-series-runtime.json',release);
    const commit=(file,value)=>{write(admin,file,value);git(admin,'add','.');git(admin,'commit','-qm','Synthetic upstream change');git(admin,'push','origin','codex/chatdev-integration');return git(admin,'rev-parse','HEAD');};
    return fn({home,admin,bare,runtime,feature,base,merge,head,main,policy,evidence,snapshot,releaseFile,auditFile,verificationFile,publicationFile,commit});
  }finally{fs.rmSync(home,{recursive:true,force:true});}
}
test('clean external published approved runtime returns the exact stable receipt binding',()=>fixture(c=>{
  const selected=selectStableBase(c.feature,c.runtime);assert.deepEqual(Object.keys(selected.binding).sort(),['base','runtime','policyDigest','remoteIdentity'].sort());assert.equal(selected.binding.base,c.base);assert.equal(selected.binding.runtime,c.head);assert.equal(selected.toolingTip,c.head);assert.deepEqual(selected.upstream.documentationCommits,[]);
}));
const docsEvidence=(c,report)=>write(c.home,'synthetic-documentation-evidence.json',{binding:report.binding,toolingTip:report.toolingTip,changesDigest:report.upstream.changesDigest,authorActor:'synthetic-author',reviewerActor:'synthetic-independent-reviewer',decision:'approved',evidence:['SYNTHETIC fixture: read every actual upstream documentation diff; no security or runtime relevance.']});
test('ordinary docs require an exact independent review, then pass without changing binding or requiring a merge',()=>fixture(c=>{
  const first=selectStableBase(c.feature,c.runtime),tip=c.commit('docs/new-note.md','Synthetic documentation only\n');
  assert.throws(()=>selectStableBase(c.feature,c.runtime),/documentation needs an exact independent relevance review/);
  const inspected=inspectStableRuntime(c.runtime);assert.deepEqual(inspected.binding,first.binding);assert.deepEqual(inspected.upstream.documentationChanges[0].paths,['docs/new-note.md']);assert.match(inspected.upstream.documentationChanges[0].diffDigest,/^[a-f0-9]{64}$/);
  acceptDocumentationReview(c.runtime,docsEvidence(c,inspected));
  const next=selectStableBase(c.feature,c.runtime);assert.deepEqual(next.binding,first.binding);assert.equal(next.toolingTip,tip);assert.deepEqual(next.upstream.documentationCommits,[tip]);assert.equal(git(c.feature,'rev-parse','HEAD'),c.merge);
}));
test('documentation proof must be independent, private, and bound to the entire exact current changeset',()=>fixture(c=>{
  c.commit('docs/new-note.md','Synthetic documentation one\n');const inspected=inspectStableRuntime(c.runtime),file=docsEvidence(c,inspected),evidence=read(file);
  write(c.home,'synthetic-documentation-evidence.json',{...evidence,reviewerActor:evidence.authorActor});assert.throws(()=>acceptDocumentationReview(c.runtime,file),/independent actor/);
  write(c.home,'synthetic-documentation-evidence.json',{...evidence,reviewerActor:evidence.authorActor+' '});assert.throws(()=>acceptDocumentationReview(c.runtime,file),/independent actor/);
  write(c.home,'synthetic-documentation-evidence.json',{...evidence,changesDigest:'0'.repeat(64)});assert.throws(()=>acceptDocumentationReview(c.runtime,file),/complete current documentation changeset/);
  write(c.home,'synthetic-documentation-evidence.json',evidence);acceptDocumentationReview(c.runtime,file);
  const proof=localPath(c.runtime,'stable-series-documentation-review.json');fs.chmodSync(proof,0o644);assert.throws(()=>selectStableBase(c.feature,c.runtime),/must be private/);fs.chmodSync(proof,0o600);
  c.commit('docs/new-note.md','Synthetic documentation two\n');assert.throws(()=>selectStableBase(c.feature,c.runtime),/review is missing, stale/);assert.throws(()=>acceptDocumentationReview(c.runtime,file),/complete current documentation changeset/);
}));
test('upstream source changes and later reversions both require a newly reviewed runtime',()=>fixture(c=>{
  c.commit('tools/runtime.mjs','// Synthetic changed security source\n');assert.throws(()=>selectStableBase(c.feature,c.runtime),/Upstream runtime\/source change/);git(c.admin,'revert','--no-edit','HEAD');git(c.admin,'push','origin','codex/chatdev-integration');assert.throws(()=>selectStableBase(c.feature,c.runtime),/Upstream runtime\/source change/);
}));
test('fresh remote policy immediately rejects revocations despite previously green approval',()=>{
  for(const kind of ['runtime','base'])fixture(c=>{assert.doesNotThrow(()=>selectStableBase(c.feature,c.runtime));c.policy[kind==='runtime'?'revokedRuntimeHeads':'revokedFeatureBases'].push(kind==='runtime'?c.head:c.base);c.commit('harness/chatdev/feature-bases.json',c.policy);assert.throws(()=>selectStableBase(c.feature,c.runtime),/revoked/);});
});
test('changed authoritative policy and malformed additive policy fields fail closed',()=>{
  fixture(c=>{c.policy.featureBase=c.main;c.commit('harness/chatdev/feature-bases.json',c.policy);assert.throws(()=>selectStableBase(c.feature,c.runtime),/authority policy changed/);});
  fixture(c=>{c.policy.allowUnreviewedRuntime=true;c.commit('harness/chatdev/feature-bases.json',c.policy);assert.throws(()=>selectStableBase(c.feature,c.runtime),/authority policy/);});
});
test('dirty runtime, local policy edits, unpublished runtime, and shared checkout are refused',()=>{
  fixture(c=>{write(c.runtime,'untracked.mjs','// Synthetic unreviewed\n');assert.throws(()=>selectStableBase(c.feature,c.runtime),/must be clean/);});
  fixture(c=>{write(c.runtime,'harness/chatdev/feature-bases.json',{...c.policy,featureBase:c.main});assert.throws(()=>selectStableBase(c.feature,c.runtime),/must be clean/);});
  fixture(c=>{git(c.runtime,'config','user.name','Synthetic fixture');git(c.runtime,'config','user.email','fixture@example.invalid');write(c.runtime,'docs/local.md','Synthetic unpublished runtime\n');git(c.runtime,'add','.');git(c.runtime,'commit','-qm','Synthetic unpublished');assert.throws(()=>selectStableBase(c.feature,c.runtime),/published remote lineage/);});
  fixture(c=>assert.throws(()=>selectStableBase(c.feature,c.feature),/separate external/));
});
test('a pin contained only in feature history is not a published tooling-lineage pin',()=>fixture(c=>assert.throws(()=>selectStableBase(c.feature,c.runtime),/published remote lineage/),{pinOutsideRuntime:true}));
test('hidden working-tree bytes and changed index blobs invalidate the exact runtime proof',()=>{
  fixture(c=>{git(c.runtime,'update-index','--assume-unchanged','tools/runtime.mjs');write(c.runtime,'tools/runtime.mjs','// Synthetic hidden source modification\n');assert.equal(git(c.runtime,'status','--porcelain=v1'),'');assert.throws(()=>selectStableBase(c.feature,c.runtime),/publication is missing or stale/);});
  fixture(c=>{const blob=execFileSync('git',['hash-object','-w','--stdin'],{cwd:c.runtime,input:'// Synthetic staged index bytes\n',encoding:'utf8'}).trim();git(c.runtime,'update-index','--cacheinfo',`100644,${blob},tools/runtime.mjs`);assert.throws(()=>selectStableBase(c.feature,c.runtime),/must be clean/);});
});
test('runtime progress remains byte/mode bound even behind hidden index flags and digest exclusions',()=>{
  for(const flag of ['--assume-unchanged','--skip-worktree'])fixture(c=>{git(c.runtime,'update-index',flag,'harness/progress.json');write(c.runtime,'harness/progress.json',{schemaVersion:1,activeStep:99,steps:[]});assert.equal(git(c.runtime,'status','--porcelain=v1'),'');assert.throws(()=>selectStableBase(c.feature,c.runtime),/Runtime progress bytes and mode must match/);});
  fixture(c=>{git(c.runtime,'update-index','--assume-unchanged','harness/progress.json');fs.chmodSync(path.join(c.runtime,'harness/progress.json'),0o755);assert.equal(git(c.runtime,'status','--porcelain=v1'),'');assert.throws(()=>selectStableBase(c.feature,c.runtime),/Runtime progress bytes and mode must match/);});
  fixture(c=>{git(c.runtime,'update-index','--skip-worktree','harness/progress.json');const file=path.join(c.runtime,'harness/progress.json');fs.renameSync(file,file+'.synthetic-backup');fs.symlinkSync(file+'.synthetic-backup',file);fs.rmSync(file+'.synthetic-backup');assert.throws(()=>selectStableBase(c.feature,c.runtime),/regular file/);});
});
test('different origin and non-ancestor rewritten remote lineage are refused',()=>{
  fixture(c=>{git(c.feature,'remote','set-url','origin',path.join(c.home,'different.git'));assert.throws(()=>selectStableBase(c.feature,c.runtime),/same origin identity/);});
  fixture(c=>{git(c.admin,'checkout','--orphan','synthetic-rewrite');git(c.admin,'rm','-rf','.');write(c.admin,'docs/unrelated.md','Synthetic unrelated history\n');git(c.admin,'add','.');git(c.admin,'commit','-qm','Synthetic unrelated');git(c.admin,'push','--force','origin','HEAD:refs/heads/codex/chatdev-integration');assert.throws(()=>selectStableBase(c.feature,c.runtime),/published remote lineage/);});
});
test('missing, stale, public and forged READY receipts cannot select an approved runtime',()=>{
  fixture(c=>{fs.rmSync(c.releaseFile);assert.throws(()=>selectStableBase(c.feature,c.runtime));});
  fixture(c=>{const v=read(c.verificationFile);v.digest='0'.repeat(64);privateJson(c.runtime,'chatdev-verification.json',v);assert.throws(()=>selectStableBase(c.feature,c.runtime),/publication is missing or stale/);});
  fixture(c=>{fs.chmodSync(c.releaseFile,0o644);assert.throws(()=>selectStableBase(c.feature,c.runtime),/must be private/);});
  fixture(c=>{const audit=read(c.auditFile);audit.snapshot.checks=[];audit.result.status='READY';privateJson(c.runtime,'stable-series-runtime-audit.json',audit);const release=read(c.releaseFile);release.auditDigest=hash(fs.readFileSync(c.auditFile));privateJson(c.runtime,'stable-series-runtime.json',release);assert.throws(()=>selectStableBase(c.feature,c.runtime),/attestation does not match/);});
});
test('attestation runtime, policy, origin, base, source and index bindings are individually required',()=>{
  for(const field of ['runtime','policyDigest','remoteIdentity','base','sourceDigest','indexDigest'])fixture(c=>{const release=read(c.releaseFile);release[field]='0'.repeat(field==='runtime'||field==='base'?40:64);privateJson(c.runtime,'stable-series-runtime.json',release);assert.throws(()=>selectStableBase(c.feature,c.runtime),/attestation does not match/);});
});
test('old base aliases and role/test source or base replay cannot satisfy a stored READY audit',()=>{
  for(const kind of ['old-base','role-base','test-source'])fixture(c=>{const audit=read(c.auditFile);if(kind==='old-base'){audit.evidence.base=audit.evidence.baseSha;delete audit.evidence.baseSha;}else if(kind==='role-base')audit.evidence.reviews[0].baseSha=c.base;else audit.evidence.tests[0].sourceDigest='0'.repeat(64);privateJson(c.runtime,'stable-series-runtime-audit.json',audit);const release=read(c.releaseFile);release.auditDigest=hash(fs.readFileSync(c.auditFile));privateJson(c.runtime,'stable-series-runtime.json',release);assert.throws(()=>selectStableBase(c.feature,c.runtime),/attestation does not match/);});
});
test('environment and extra arguments cannot override the reviewed feature base',()=>fixture(c=>{
  const names=['CROZZTUBE_FEATURE_BASE','SERIES_BASE','STABLE_RUNTIME_ROOT'],saved=Object.fromEntries(names.map(k=>[k,process.env[k]]));try{for(const k of names)process.env[k]=c.main;const r=selectStableBase(c.feature,c.runtime,{base:c.main,runtime:c.feature});assert.equal(r.binding.base,c.base);}finally{for(const k of names)if(saved[k]===undefined)delete process.env[k];else process.env[k]=saved[k];}
}));
test('acceptance cannot replace real verification/publication with a passed:true argument',()=>fixture(c=>{
  fs.rmSync(c.verificationFile);const fake=write(c.home,'synthetic-passed.json',{passed:true,status:'READY'});assert.throws(()=>acceptStableRuntime(c.runtime,fake));assert.equal(read(c.releaseFile).runtime,c.head);
}));
test('a copied project in a foreign GitHub repository cannot approve its own PR3 runtime',()=>fixture(c=>{
  // Local Git transport rewrite keeps this entirely within the synthetic bare
  // fixture; the raw hosted identity is deliberately foreign. No GitHub call.
  const foreign='https://github.com/foreign/synthetic-copy.git';git(c.runtime,'config',`url.${c.bare}.insteadOf`,foreign);git(c.runtime,'remote','set-url','origin',foreign);
  const file=write(c.home,'synthetic-real-shaped-evidence.json',c.evidence);assert.throws(()=>acceptStableRuntime(c.runtime,file),/not the policy-approved GitHub repository/);
}));

function syntheticGithub(c,options,fn){
  const originalPath=process.env.PATH,bin=path.join(c.home,'synthetic-bin');fs.mkdirSync(bin);
  // The raw URL satisfies the production provenance contract; Git transports
  // are rewritten locally and gh is replaced by this fixture-only executable.
  const hosted='https://github.com/benjarogit/crozztube.git';git(c.runtime,'config',`url.${c.bare}.insteadOf`,hosted);git(c.runtime,'remote','set-url','origin',hosted);
  const file=write(c.home,'synthetic-fixture-data.json',{head:c.head,base:c.main,repo:c.policy.repository,refReads:path.join(c.home,'synthetic-ref-reads'),...options});
  const script=`#!${process.execPath}
const fs=require('node:fs');const x=JSON.parse(fs.readFileSync(${JSON.stringify(file)},'utf8')),args=process.argv.slice(2);let value;
if(args[0]==='repo')value={nameWithOwner:x.repo};else{
const endpoint=args.find(a=>a.startsWith('repos/'));
if(args[1]==='graphql')value={data:{repository:{pullRequest:{reviewThreads:{nodes:[],pageInfo:{hasNextPage:false}}}}}};
else if(endpoint==='repos/'+x.repo+'/git/ref/heads/main'){let n=0;try{n=Number(fs.readFileSync(x.refReads,'utf8'));}catch{}fs.writeFileSync(x.refReads,String(n+1));if(x.missingTip||x.missingTipAfter!==undefined&&n>=x.missingTipAfter)throw Error('Synthetic missing ref');value={ref:'refs/heads/main',object:{type:'commit',sha:x.changedTip||x.tipMovesAfter!==undefined&&n>=x.tipMovesAfter?x.changedTip||x.tipMovesTo:x.base}};}
else if(endpoint==='repos/'+x.repo+'/pulls/3')value={head:{sha:x.head,ref:'codex/chatdev-integration',repo:{full_name:x.repo}},base:{sha:x.changedBase||x.base,ref:'main',repo:{full_name:x.repo}},state:'open',draft:false,mergeable:true,mergeable_state:'clean',user:{login:'synthetic-author'},html_url:'https://github.com/'+x.repo+'/pull/3'};
else if(endpoint?.includes('/check-runs?'))value=[{check_runs:[{id:1,head_sha:x.head,status:'completed',conclusion:x.failedCi?'failure':'success',name:'contracts',app:{id:1,slug:'github-actions'},html_url:'https://github.com/'+x.repo+'/actions/runs/1'}]}];
else if(endpoint?.includes('/statuses?'))value=[[]];
else if(endpoint?.includes('/status?'))value={sha:x.head,state:'pending',total_count:0};
else if(endpoint?.includes('/reviews?')||endpoint?.includes('/comments?'))value=[[]];
else throw Error('Unknown synthetic endpoint');}
process.stdout.write(JSON.stringify(value));
`;
  fs.writeFileSync(path.join(bin,'gh'),script,{mode:0o700});process.env.PATH=bin+path.delimiter+originalPath;
  try{return fn();}finally{process.env.PATH=originalPath;}
}
test('explicit acceptance collects fresh synthetic GitHub CI and exact-base independent evidence before issuing a private release',()=>fixture(c=>syntheticGithub(c,{},()=>{
  fs.rmSync(c.releaseFile);fs.rmSync(c.auditFile);const file=write(c.home,'synthetic-release-evidence.json',c.evidence);
  const accepted=acceptStableRuntime(c.runtime,file);assert.equal(accepted.binding.runtime,c.head);assert.equal(accepted.binding.base,c.base);assert.equal(read(c.releaseFile).repository,c.policy.repository);assert.equal(read(c.auditFile).snapshot.baseSha,c.main);assert.equal(read(c.auditFile).result.status,'READY');assert.equal(fs.statSync(c.releaseFile).mode&0o077,0);assert.doesNotThrow(()=>selectStableBase(c.feature,c.runtime));
})));
test('runtime acceptance uses the fresh exact main ref while retaining a different reported pull base diagnostically',()=>fixture(c=>syntheticGithub(c,{changedBase:c.base},()=>{
  const file=write(c.home,'synthetic-release-evidence.json',c.evidence);
  assert.doesNotThrow(()=>acceptStableRuntime(c.runtime,file));
  const audit=read(c.auditFile);assert.equal(audit.snapshot.schemaVersion,2);assert.equal(audit.snapshot.baseSource,'github-base-branch-ref');
  assert.equal(audit.snapshot.reportedBaseSha,c.base);assert.equal(audit.snapshot.baseSha,c.main);assert.equal(audit.base,c.main);
})));
test('runtime acceptance refuses unreadable refs and advances during collection or the final approval read',()=>{
  for(const options of [{missingTip:true},{tipMovesAfter:2,tipMovesTo:'a'.repeat(40)},{tipMovesAfter:3,tipMovesTo:'a'.repeat(40)},{missingTipAfter:3}])fixture(c=>syntheticGithub(c,options,()=>{
    const before=fs.readFileSync(c.releaseFile,'utf8'),file=write(c.home,'synthetic-release-evidence.json',c.evidence);
    assert.throws(()=>acceptStableRuntime(c.runtime,file));assert.equal(fs.readFileSync(c.releaseFile,'utf8'),before);
  }));
});
test('a legacy v1 stored release audit cannot be reinterpreted as fresh-ref source evidence',()=>fixture(c=>{
  const audit=read(c.auditFile);audit.snapshot.schemaVersion=1;delete audit.snapshot.baseSource;
  audit.result.schemaVersion=1;delete audit.result.baseSource;privateJson(c.runtime,'stable-series-runtime-audit.json',audit);
  const release=read(c.releaseFile);release.auditDigest=hash(fs.readFileSync(c.auditFile));privateJson(c.runtime,'stable-series-runtime.json',release);
  assert.throws(()=>selectStableBase(c.feature,c.runtime),/attestation does not match/);
}));
test('fresh synthetic failed CI or changed actual PR base cannot reuse an old successful acceptance',()=>{
  for(const kind of ['failed-ci','changed-tip'])fixture(c=>syntheticGithub(c,kind==='failed-ci'?{failedCi:true}:{changedTip:c.base},()=>{
    const before=fs.readFileSync(c.releaseFile,'utf8'),file=write(c.home,'synthetic-release-evidence.json',c.evidence);assert.throws(()=>acceptStableRuntime(c.runtime,file),kind==='failed-ci'?/must be READY/:/does not match the published head\/base/);assert.equal(fs.readFileSync(c.releaseFile,'utf8'),before);
  }));
});
test('an environment-selected GitHub host cannot replace the policy-approved public GitHub audit',()=>fixture(c=>syntheticGithub(c,{},()=>{
  const previous=process.env.GH_HOST;try{process.env.GH_HOST='synthetic-attacker.invalid';const file=write(c.home,'synthetic-release-evidence.json',c.evidence);assert.throws(()=>acceptStableRuntime(c.runtime,file),/environment-selected GitHub host/);}finally{if(previous===undefined)delete process.env.GH_HOST;else process.env.GH_HOST=previous;}
})));
