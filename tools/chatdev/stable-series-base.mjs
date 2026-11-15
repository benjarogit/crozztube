import fs from 'node:fs';
import path from 'node:path';
import { createHash, randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { digest, indexDigest, localPath } from '../harness/workflow.mjs';
import { collect, assess, sameBaseIdentity, baseIdentity, readBaseTip } from './pr-audit.mjs';

const POLICY='harness/chatdev/feature-bases.json';
const RELEASE='stable-series-runtime.json';
const AUDIT='stable-series-runtime-audit.json';
const DOCUMENTATION='stable-series-documentation-review.json';
const toolingBranch='codex/chatdev-integration', featureBranch='codex/series-seasons';
const sha=/^[a-f0-9]{40}$/, sha256=/^[a-f0-9]{64}$/;
const hash=value=>createHash('sha256').update(value).digest('hex');
const canonical=value=>JSON.stringify(value && typeof value==='object' ? Array.isArray(value)?value.map(v=>JSON.parse(canonical(v))):Object.fromEntries(Object.keys(value).sort().map(k=>[k,JSON.parse(canonical(value[k]))])) : value);
const run=(root,command,args)=>{try{return execFileSync(command,args,{cwd:root,encoding:'utf8',timeout:90000,maxBuffer:20_000_000,stdio:['ignore','pipe','pipe']}).trimEnd();}catch{throw Error('Stable runtime command failed; command output suppressed.');}};
const git=(root,...args)=>run(root,'git',args);
const parse=text=>{try{return JSON.parse(text);}catch{throw Error('Invalid stable runtime JSON; contents suppressed.');}};
const regular=file=>{const stat=fs.lstatSync(file);if(!stat.isFile()||stat.isSymbolicLink())throw Error('Stable runtime evidence must be a regular file.');return stat;};
const json=file=>{regular(file);return parse(fs.readFileSync(file,'utf8'));};
const objectHash=value=>hash(canonical(value));
const assertAncestor=(root,older,newer)=>{try{git(root,'merge-base','--is-ancestor',older,newer);}catch{throw Error('Stable runtime and feature anchors must retain published remote lineage.');}};
const remoteIdentity=root=>hash('fetch\0'+git(root,'remote','get-url','--all','origin')+'\0push\0'+git(root,'remote','get-url','--push','--all','origin'));
function repositoryRoot(root){const real=fs.realpathSync(root);if(fs.realpathSync(git(real,'rev-parse','--show-toplevel'))!==real)throw Error('Use the exact repository root.');return real;}
function cleanRuntime(root){
  if(git(root,'status','--porcelain=v1','--untracked-files=all'))throw Error('External stable runtime must be clean.');
  // workflow.digest/indexDigest deliberately omit feature progress. An external
  // immutable runtime must nevertheless retain its own exact committed progress
  // bytes and mode, even behind assume-unchanged/skip-worktree index flags.
  const file='harness/progress.json',entry=git(root,'ls-tree','HEAD','--',file),match=entry.match(/^(100644|100755) blob ([a-f0-9]{40})\tharness\/progress\.json$/);
  if(!match||git(root,'ls-files','--stage','--',file)!==`${match[1]} ${match[2]} 0\t${file}`)throw Error('Runtime progress must retain its committed HEAD/index blob and mode.');
  const stat=regular(path.join(root,file)),mode=stat.mode&0o111?'100755':'100644';let committed;
  try{committed=execFileSync('git',['cat-file','blob',`HEAD:${file}`],{cwd:root,maxBuffer:20_000_000,timeout:15000,stdio:['ignore','pipe','pipe']});}catch{throw Error('Cannot inspect committed runtime progress; contents suppressed.');}
  if(mode!==match[1]||!fs.readFileSync(path.join(root,file)).equals(committed))throw Error('Runtime progress bytes and mode must match committed HEAD.');
  return git(root,'rev-parse','HEAD');
}
function policy(value){
  const keys=['schemaVersion','repository','toolingBranch','featureBranch','featureBase','featureMerge','runtimePr','auditBaseBranch','requiredChecks','revokedRuntimeHeads','revokedFeatureBases'];
  if(!value||canonical(Object.keys(value).sort())!==canonical(keys.sort())||value.schemaVersion!==1||value.repository!=='benjarogit/crozztube'||value.toolingBranch!==toolingBranch||value.featureBranch!==featureBranch||value.runtimePr!==3||value.auditBaseBranch!=='main'||!sha.test(value.featureBase)||!sha.test(value.featureMerge)||canonical(value.requiredChecks)!==canonical(['contracts'])||['revokedRuntimeHeads','revokedFeatureBases'].some(k=>!Array.isArray(value[k])||value[k].some(x=>!sha.test(x))||new Set(value[k]).size!==value[k].length))throw Error('Unsupported stable feature-base authority policy.');
  return value;
}
function localPolicy(root){regular(path.join(root,POLICY));const raw=git(root,'show',`HEAD:${POLICY}`),value=policy(parse(raw));if(canonical(json(path.join(root,POLICY)))!==canonical(value))throw Error('Runtime policy differs from its committed source.');return value;}
function remoteTip(root){const ref=`refs/heads/${toolingBranch}`,read=()=>git(root,'ls-remote','--exit-code','--heads','origin',ref).split(/\s+/)[0],tip=read();if(!sha.test(tip))throw Error('Published tooling tip is unavailable.');git(root,'fetch','--quiet','--no-tags','origin',tip);if(read()!==tip)throw Error('Published tooling changed while checking; retry.');return tip;}
function remoteContext(root,runtime,local){
  const tip=remoteTip(root);assertAncestor(root,runtime,tip);assertAncestor(root,local.featureBase,runtime);
  const latest=policy(parse(git(root,'show',`${tip}:${POLICY}`)));
  if(latest.revokedRuntimeHeads.includes(runtime)||latest.revokedFeatureBases.includes(local.featureBase))throw Error('The published policy revoked this runtime or feature base.');
  if(canonical(latest)!==canonical(local))throw Error('Published authority policy changed; review and approve a new runtime.');
  // Inspect EVERY intervening commit, including changes later reverted. A
  // net-tree comparison would silently accept a reverted security modification.
  const commits=git(root,'rev-list','--reverse',`${runtime}..${tip}`).split('\n').filter(Boolean),documentationCommits=[],documentationChanges=[];
  for(const commit of commits){
    const parents=git(root,'show','-s','--format=%P',commit).split(' ').filter(Boolean);
    const files=git(root,'diff-tree','--root','-m','--no-commit-id','--name-only','-r',commit).split('\n').filter(Boolean);
    if(parents.length!==1||files.some(file=>!/^docs\/[^\n]+\.md$/.test(file)&&!/^tools\/chatdev\/README\.md$/.test(file)))throw Error('Upstream runtime/source change requires reviewed relevance and a new approved runtime.');
    documentationCommits.push(commit);
    documentationChanges.push({commit,paths:[...new Set(files)].sort(),diffDigest:hash(git(root,'show','--format=','--binary','--full-index',commit))});
  }
  return {toolingTip:tip,upstream:{documentationCommits,documentationChanges,changesDigest:objectHash(documentationChanges),documentationReviewRequired:documentationCommits.length>0,classification:'Documentation paths only; semantic relevance requires a separate independent private review.'}};
}
function receiptFiles(root){return {verification:localPath(root,'chatdev-verification.json'),publication:localPath(root,'chatdev-publication.json')};}
function publishedProof(root,runtime){
  const files=receiptFiles(root),verification=json(files.verification),publication=json(files.publication),source=digest(root),index=indexDigest(root);
  if(verification.branch!==toolingBranch||publication.branch!==toolingBranch||publication.commit!==runtime||publication.base!==verification.base||!sha.test(publication.base||'')||publication.checkedAt!==verification.checkedAt||verification.purpose!=='chatdev-tooling-only'||verification.digest!==source||verification.indexDigest!==index||!verification.checkedAt)throw Error('Existing ChatDev verification/publication is missing or stale for this runtime.');
  assertAncestor(root,publication.base,runtime);
  return {sourceDigest:source,indexDigest:index,verificationDigest:hash(fs.readFileSync(files.verification)),publicationDigest:hash(fs.readFileSync(files.publication)),base:publication.base,checkedAt:verification.checkedAt};
}
function releaseProof(root,runtime,p,identity){
  const file=localPath(root,RELEASE);if((regular(file).mode&0o077)!==0)throw Error('Stable runtime attestation must be private.');
  const release=json(file),proof=publishedProof(root,runtime),auditFile=localPath(root,AUDIT);
  if((regular(auditFile).mode&0o077)!==0)throw Error('Stable runtime audit must be private.');
  const audit=json(auditFile);
  if(release.schemaVersion!==1||release.purpose!=='approved-external-series-runtime'||release.repository!==p.repository||release.runtime!==runtime||release.policyDigest!==objectHash(p)||release.remoteIdentity!==identity||release.base!==proof.base||['sourceDigest','indexDigest','verificationDigest','publicationDigest'].some(k=>release[k]!==proof[k])||!sha256.test(release.auditDigest||'')||release.auditDigest!==hash(fs.readFileSync(auditFile))||release.auditHead!==runtime||release.auditBase!==proof.base||audit.snapshot?.repository!==p.repository||audit.snapshot?.head!==runtime||audit.snapshot?.pr!==3||audit.result?.status!=='READY'||audit.result?.head!==runtime||audit.base!==proof.base||!boundEvidence(audit.evidence,runtime,proof,p.repository)||!sameBaseIdentity(audit.snapshot,audit.evidence)||canonical(assess(audit.snapshot,audit.evidence))!==canonical(audit.result))throw Error('Stable runtime attestation does not match this exact approved source, base and audit.');
  return proof;
}

/** No CLI/env policy overrides. The adapter must derive runtimeRoot from its own import.meta.url. */
export function selectStableBase(featureRoot,runtimeRoot){
  const feature=repositoryRoot(featureRoot),runtimeDirectory=repositoryRoot(runtimeRoot);
  if(feature===runtimeDirectory||feature.startsWith(runtimeDirectory+path.sep)||runtimeDirectory.startsWith(feature+path.sep))throw Error('Use a separate external stable runtime checkout.');
  if(git(feature,'branch','--show-current')!==featureBranch)throw Error('Stable series base is only for the approved feature branch.');
  const runtime=cleanRuntime(runtimeDirectory),p=localPolicy(runtimeDirectory),identity=remoteIdentity(runtimeDirectory);
  if(remoteIdentity(feature)!==identity)throw Error('Feature and external runtime must use the same origin identity.');
  const report=remoteContext(runtimeDirectory,runtime,p);
  releaseProof(runtimeDirectory,runtime,p,identity);
  // The feature still carries the five exact approved integrations. Its adapter
  // audits their trees and complete historical feature scope separately.
  assertAncestor(feature,p.featureBase,'HEAD');assertAncestor(feature,p.featureMerge,'HEAD');
  const parents=git(feature,'show','-s','--format=%P',p.featureMerge).split(' ');
  if(parents.length!==2||parents[1]!==p.featureBase)throw Error('Pinned feature merge must retain its exact published tooling parent.');
  const binding={base:p.featureBase,runtime,policyDigest:objectHash(p),remoteIdentity:identity};
  if(report.upstream.documentationReviewRequired)documentationProof(runtimeDirectory,binding,report);
  return {...binding,binding,...report};
}

/** Read-only inspection exposes every actual upstream docs change for review.
 * It does not authorize feature verification/publication or create a receipt.
 */
export function inspectStableRuntime(runtimeRoot){
  const root=repositoryRoot(runtimeRoot),runtime=cleanRuntime(root),p=localPolicy(root),identity=remoteIdentity(root),report=remoteContext(root,runtime,p);
  releaseProof(root,runtime,p,identity);
  const binding={base:p.featureBase,runtime,policyDigest:objectHash(p),remoteIdentity:identity};
  return {...binding,binding,...report};
}

function independentDocumentationEvidence(value,binding,report){
  return canonical(value?.binding)===canonical(binding)&&value?.toolingTip===report.toolingTip&&value?.changesDigest===report.upstream.changesDigest&&value?.decision==='approved'&&typeof value.authorActor==='string'&&Boolean(value.authorActor.trim())&&typeof value.reviewerActor==='string'&&Boolean(value.reviewerActor.trim())&&value.authorActor.trim()!==value.reviewerActor.trim()&&Array.isArray(value.evidence)&&value.evidence.length>0&&value.evidence.every(item=>typeof item==='string'&&Boolean(item.trim()));
}
function documentationProof(root,binding,report){
  const file=localPath(root,DOCUMENTATION);
  if(!fs.existsSync(file))throw Error('Upstream documentation needs an exact independent relevance review before feature verification/publication.');
  if((regular(file).mode&0o077)!==0)throw Error('Documentation relevance review must be private.');
  const proof=json(file);
  if(proof.schemaVersion!==1||proof.purpose!=='reviewed-upstream-documentation'||!independentDocumentationEvidence(proof,binding,report))throw Error('Documentation relevance review is missing, stale or not independent for this complete changeset.');
}

/** Explicit source-bound independent review; no merge of ordinary docs needed.
 * Actor names/evidence are reviewer assertions, not authenticated identities.
 */
export function acceptDocumentationReview(runtimeRoot,evidenceFile){
  const root=repositoryRoot(runtimeRoot),inspected=inspectStableRuntime(root),evidence=json(evidenceFile);
  if(!inspected.upstream.documentationReviewRequired)throw Error('There are no upstream documentation changes to review.');
  if(!independentDocumentationEvidence(evidence,inspected.binding,inspected))throw Error('Review the complete current documentation changeset with an independent actor and exact binding.');
  const final=inspectStableRuntime(root);
  if(canonical(final)!==canonical(inspected))throw Error('Published source or policy changed during documentation review; inspect again.');
  atomic(localPath(root,DOCUMENTATION),{...evidence,authorActor:evidence.authorActor.trim(),reviewerActor:evidence.reviewerActor.trim(),schemaVersion:1,purpose:'reviewed-upstream-documentation',reviewedAt:new Date().toISOString()});
  return {binding:inspected.binding,toolingTip:inspected.toolingTip,changesDigest:inspected.upstream.changesDigest,purpose:'reviewed-upstream-documentation'};
}

function githubRepository(root){
  const url=git(root,'config','--get','remote.origin.url');let repo;
  const scp=url.match(/^git@github\.com:([\w.-]+\/[\w.-]+?)(?:\.git)?$/);
  if(scp)repo=scp[1];else{try{const u=new URL(url);if(u.hostname==='github.com'&&u.protocol==='https:')repo=u.pathname.replace(/^\//,'').replace(/\.git$/,'');}catch{}}
  if(!repo||! /^[\w.-]+\/[\w.-]+$/.test(repo))throw Error('Runtime approval requires the actual GitHub origin repository.');return repo;
}
const gh=(root,endpoint)=>parse(run(root,'gh',['api','--hostname','github.com',endpoint]));
function boundEvidence(evidence,runtime,proof,repo){
  const target={baseSha:proof.base,baseRef:'main',baseRepository:repo};
  const matches=item=>item?.head===runtime&&item?.sourceDigest===proof.sourceDigest&&sameBaseIdentity(item,target);
  return matches(evidence)&&Array.isArray(evidence.reviews)&&Array.isArray(evidence.tests)&&[...evidence.reviews,...evidence.tests].every(matches);
}
function atomic(file,value){fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});const temporary=file+'.'+randomUUID()+'.tmp';try{fs.writeFileSync(temporary,JSON.stringify(value,null,2)+'\n',{mode:0o600,flag:'wx'});fs.renameSync(temporary,file);}finally{fs.rmSync(temporary,{force:true});}}

/** Explicit private acceptance, never called by selection/verify/publication.
 * Uses real existing publication proofs plus fresh GitHub collection and
 * independent evidence, rather than accepting a supplied passed/READY boolean.
 */
export function acceptStableRuntime(runtimeRoot,evidenceFile){
  if(process.env.GH_HOST&&process.env.GH_HOST!=='github.com')throw Error('Runtime approval cannot use an environment-selected GitHub host.');
  const root=repositoryRoot(runtimeRoot),runtime=cleanRuntime(root),p=localPolicy(root),identity=remoteIdentity(root),report=remoteContext(root,runtime,p);
  if(git(root,'branch','--show-current')!==toolingBranch||report.toolingTip!==runtime)throw Error('Approve the exact clean published tooling tip on its dedicated branch.');
  const proof=publishedProof(root,runtime),evidence=json(evidenceFile),repo=githubRepository(root);
  if(repo!==p.repository)throw Error('Runtime origin is not the policy-approved GitHub repository.');
  if(!boundEvidence(evidence,runtime,proof,repo))throw Error('Independent review and tests must bind the exact runtime head, base identity and source digest.');
  const before=gh(root,`repos/${repo}/pulls/3`);
  const matches=pr=>pr.head?.sha===runtime&&pr.head?.ref===toolingBranch&&pr.base?.ref==='main'&&pr.head?.repo?.full_name===repo&&pr.base?.repo?.full_name===repo&&pr.state==='open'&&pr.draft===false&&readBaseTip(baseIdentity(pr),endpoint=>gh(root,endpoint))===proof.base;
  if(!matches(before))throw Error('Runtime PR3 does not match the published head/base.');
  const snapshot=collect(root,3),result=assess(snapshot,evidence);
  if(snapshot.repository!==repo||snapshot.head!==runtime||snapshot.pr!==3||snapshot.baseSha!==proof.base||result.status!=='READY'||result.head!==runtime)throw Error('Fresh independent PR3 review and required exact-head CI must be READY.');
  if(!matches(gh(root,`repos/${repo}/pulls/3`))||cleanRuntime(root)!==runtime||canonical(publishedProof(root,runtime))!==canonical(proof)||remoteTip(root)!==runtime)throw Error('Runtime source, publication or PR base changed during approval.');
  const audit={snapshot,result,evidence,base:proof.base},auditFile=localPath(root,AUDIT);
  atomic(auditFile,audit);
  const release={schemaVersion:1,purpose:'approved-external-series-runtime',repository:repo,runtime,policyDigest:objectHash(p),remoteIdentity:identity,...proof,auditHead:runtime,auditBase:proof.base,auditDigest:hash(fs.readFileSync(auditFile)),approvedAt:new Date().toISOString()};
  atomic(localPath(root,RELEASE),release);
  return {binding:{base:p.featureBase,runtime,policyDigest:release.policyDigest,remoteIdentity:identity},...report,purpose:release.purpose};
}
