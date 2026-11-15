import fs from 'node:fs';
import path from 'node:path';
import { createHash, randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';

const hash = value => createHash('sha256').update(String(value ?? '')).digest('hex');
const safeUrl = value => {
  try { const u = new URL(value); if(u.protocol !== 'https:' || u.hostname !== 'github.com') return null; return u.origin + u.pathname; } catch { return null; }
};
const run = (root, command, args) => {
  try { return execFileSync(command,args,{cwd:root,encoding:'utf8',timeout:90000,maxBuffer:50000000,stdio:['ignore','pipe','pipe']}).trim(); }
  catch { throw Error('PR audit command failed; command output suppressed'); }
};
const parse = value => { try { return JSON.parse(value); } catch { throw Error('Invalid PR audit JSON; contents suppressed'); } };
const api = (root, endpoint, paginate=false) => parse(run(root,'gh',['api',...(paginate?['--paginate','--slurp']:[]),endpoint]));
const flat = pages => pages.flat();
const comment = (item,kind) => ({id:`${kind}:${item.id}`,bodyHash:hash(item.body),hasBody:Boolean(item.body?.trim()),url:safeUrl(item.html_url),authorHash:hash(item.user?.login),commit:item.commit_id || null,createdAt:item.created_at || item.submitted_at,updatedAt:item.updated_at || item.submitted_at});
const completenessKeys=['checks','statuses','reviews','issueComments','inlineComments','reviewThreads','headStable'];
const roles=['orchestrator','project-manager','engineer','analyst','qa','quality','architecture','security','performance','ux','assets','documentation','localization','repository','infrastructure'];

/** Read-only GitHub snapshot. Raw comment bodies are hashed in memory and never persisted. */
export function collect(root,pr) {
  if(!/^[1-9]\d*$/.test(String(pr))) throw Error('Expected a positive PR number');
  const repo=parse(run(root,'gh',['repo','view','--json','nameWithOwner'])).nameWithOwner;
  if(!/^[\w.-]+\/[\w.-]+$/.test(repo)) throw Error('Invalid GitHub repository identity');
  const base=`repos/${repo}`, initial=api(root,`${base}/pulls/${pr}`), head=initial.head?.sha;
  if(!/^[a-f0-9]{40,64}$/.test(head || '')) throw Error('Missing PR head');
  const snapshot={schemaVersion:1,repository:repo,pr:Number(pr),head,url:safeUrl(initial.html_url),state:initial.state,draft:Boolean(initial.draft),mergeable:initial.mergeable,mergeableState:initial.mergeable_state,authorHash:hash(initial.user?.login),collectedAt:new Date().toISOString(),complete:Object.fromEntries(completenessKeys.map(k=>[k,false])),checks:[],statuses:[],reviews:[],comments:[],threads:[]};
  const attempt=(key,fn)=>{try{fn();snapshot.complete[key]=true;}catch{snapshot.complete[key]=false;}};
  attempt('checks',()=>{ const pages=api(root,`${base}/commits/${head}/check-runs?per_page=100&filter=latest`,true); snapshot.checks=pages.flatMap(p=>p.check_runs).map(c=>({id:c.id,head:c.head_sha,key:hash(`${c.app?.id}:${c.name}`),nameHash:hash(c.name),appSlugHash:hash(c.app?.slug),status:c.status,conclusion:c.conclusion,url:safeUrl(c.html_url)})); });
  attempt('statuses',()=>{
    const combined=api(root,`${base}/commits/${head}/status?per_page=100`), all=flat(api(root,`${base}/commits/${head}/statuses?per_page=100`,true));
    snapshot.combinedStatus={head:combined.sha,state:combined.state,total:combined.total_count};
    snapshot.statuses=all.map(s=>({id:s.id,head,key:hash(s.context),state:s.state,createdAt:s.created_at,url:safeUrl(s.target_url)}));
    if(combined.sha!==head || Number(combined.total_count)>new Set(all.map(s=>s.context)).size)throw Error('Incomplete combined status');
  });
  attempt('reviews',()=>{const list=flat(api(root,`${base}/pulls/${pr}/reviews?per_page=100`,true));snapshot.reviews=list.map(r=>({id:r.id,actorHash:hash(r.user?.login),state:r.state,head:r.commit_id,submittedAt:r.submitted_at}));snapshot.comments.push(...list.filter(r=>r.body?.trim()).map(r=>comment(r,'review')));});
  attempt('issueComments',()=>{snapshot.comments.push(...flat(api(root,`${base}/issues/${pr}/comments?per_page=100`,true)).map(c=>comment(c,'issue')));});
  attempt('inlineComments',()=>{snapshot.comments.push(...flat(api(root,`${base}/pulls/${pr}/comments?per_page=100`,true)).map(c=>comment(c,'inline')));});
  attempt('reviewThreads',()=>{
    const [owner,name]=repo.split('/');let cursor=null;const seen=new Set();
    do {
      const query='query($owner:String!,$name:String!,$number:Int!,$cursor:String){repository(owner:$owner,name:$name){pullRequest(number:$number){reviewThreads(first:100,after:$cursor){nodes{id isResolved comments(first:100){nodes{databaseId} pageInfo{hasNextPage}}} pageInfo{hasNextPage endCursor}}}}}';
      const args=['api','graphql','-f',`query=${query}`,'-F',`owner=${owner}`,'-F',`name=${name}`,'-F',`number=${pr}`,...(cursor?['-F',`cursor=${cursor}`]:[])];
      const response=parse(run(root,'gh',args)), page=response.data?.repository?.pullRequest?.reviewThreads;
      if(response.errors || !page || !Array.isArray(page.nodes))throw Error('Incomplete threads');
      for(const t of page.nodes){if(t.comments?.pageInfo?.hasNextPage)throw Error('Nested thread comment pagination not complete');snapshot.threads.push({id:hash(t.id),resolved:t.isResolved===true,comments:t.comments.nodes.map(c=>`inline:${c.databaseId}`)});}
      if(!page.pageInfo.hasNextPage)break;
      cursor=page.pageInfo.endCursor;if(!cursor || seen.has(cursor))throw Error('Thread pagination did not advance');seen.add(cursor);
    }while(true);
  });
  attempt('headStable',()=>{const current=api(root,`${base}/pulls/${pr}`);if(current.head?.sha!==head||current.state!==initial.state||Boolean(current.draft)!==snapshot.draft)throw Error('PR changed during collection');snapshot.mergeable=current.mergeable;snapshot.mergeableState=current.mergeable_state;});
  return snapshot;
}

/**
 * Evidence is asserted by its supplying reviewer, not authenticated agent identity.
 * {head,authorActor,requiredRoles, reviews:[{role,actor,result,head,evidence:[]}],
 * tests:[{name,result,head,evidence:[]}], commentDispositions:{id:{classification,
 * reason,bodyHash,head}}. Editor interoperability belongs to Orqen and is
 * reported separately; no supplied flag can fabricate runtime verification.
 * READY never grants merge, deployment, permission or harness acceptance.
 */
export function assess(snapshot,evidence={}) {
  const blocked=new Set(), changes=new Set();
  if(!snapshot||typeof snapshot!=='object')snapshot={};
  if(!evidence||typeof evidence!=='object')evidence={};
  for(const key of ['checks','statuses','reviews','comments','threads'])if(!Array.isArray(snapshot[key])){blocked.add('INVALID_'+key.toUpperCase());snapshot={...snapshot,[key]:[]};}
  for(const key of ['reviews','tests'])if(!Array.isArray(evidence[key])){blocked.add('INVALID_EVIDENCE_'+key.toUpperCase());evidence={...evidence,[key]:[]};}
  const head=snapshot.head;
  const block=code=>blocked.add(code), fail=code=>changes.add(code);
  if(!snapshot || snapshot.schemaVersion!==1 || !/^[a-f0-9]{40,64}$/.test(head||''))block('INVALID_SNAPSHOT');
  for(const key of completenessKeys)if(snapshot?.complete?.[key]!==true)block(`INCOMPLETE_${key.toUpperCase()}`);
  if(snapshot?.state!=='open'||snapshot?.draft!==false)block('PR_NOT_OPEN_FOR_REVIEW');
  if(snapshot.mergeable===false||snapshot.mergeableState==='dirty')fail('MERGE_CONFLICT');else if(snapshot.mergeable!==true)block('MERGEABILITY_UNKNOWN');
  if(['blocked','behind','unknown','draft','unstable'].includes(snapshot.mergeableState))block('MERGE_REQUIREMENTS_UNSATISFIED');
  if(evidence.head!==head)block('STALE_OR_MISSING_EVIDENCE');
  let passingCi=0;
  if(!Array.isArray(snapshot?.checks)||!Array.isArray(snapshot?.statuses))block('INVALID_CI');
  for(const c of snapshot?.checks||[]){
    if(c.head!==head){block('STALE_CHECK');continue;}
    if(c.status!=='completed'){block('CI_PENDING');continue;}
    if(['failure','cancelled','timed_out','startup_failure'].includes(c.conclusion))fail('CI_FAILED');
    else if(c.conclusion==='success')passingCi++;
    else if(!['neutral','skipped'].includes(c.conclusion))block('CI_UNKNOWN');
  }
  // The statuses endpoint contains history: only the latest value per context counts.
  const statuses=new Map();for(const s of snapshot?.statuses||[]){const old=statuses.get(s.key);if(!old||String(s.createdAt)>String(old.createdAt)||s.createdAt===old.createdAt&&s.id>old.id)statuses.set(s.key,s);}
  for(const s of statuses.values()){
    if(s.head!==head){block('STALE_STATUS');continue;}
    if(['failure','error'].includes(s.state))fail('CI_FAILED');else if(s.state==='success')passingCi++;else block('CI_PENDING_OR_UNKNOWN');
  }
  if(!passingCi)block('NO_PASSING_CI');
  // Tracked tooling policy: a bot summary is not the required Actions job.
  const contracts=(snapshot.checks||[]).filter(c=>c.nameHash===hash('contracts')&&c.appSlugHash===hash('github-actions'));
  if(!contracts.length||contracts.some(c=>c.head!==head||c.status!=='completed'||c.conclusion!=='success'))block('REQUIRED_CONTRACTS_NOT_PASSED');
  if(snapshot?.combinedStatus?.head!==head)block('MISSING_COMBINED_STATUS');
  if(snapshot?.combinedStatus?.total>0&&snapshot.combinedStatus.state!=='success'){
    if(['failure','error'].includes(snapshot.combinedStatus.state))fail('CI_FAILED');else block('COMBINED_STATUS_PENDING');
  }
  const decisions=new Map();
  for(const r of [...snapshot?.reviews||[]].sort((a,b)=>String(a.submittedAt).localeCompare(String(b.submittedAt))||a.id-b.id)){
    if(['APPROVED','CHANGES_REQUESTED','DISMISSED'].includes(r.state))decisions.set(r.actorHash,r.state);
    else if(!['COMMENTED','PENDING'].includes(r.state))block('REVIEW_STATE_UNKNOWN');
    if(r.state==='PENDING')block('PENDING_REVIEW');
  }
  if([...decisions.values()].includes('CHANGES_REQUESTED'))block('OUTSTANDING_CHANGES_REQUESTED');
  if(!Array.isArray(snapshot?.threads))block('INCOMPLETE_REVIEW_THREADS');
  if(snapshot?.threads?.some(t=>t.resolved!==true))block('UNRESOLVED_REVIEW_THREAD');
  if(!Array.isArray(snapshot?.comments))block('INCOMPLETE_COMMENTS');
  for(const c of snapshot?.comments||[]){
    const d=evidence.commentDispositions?.[c.id];
    if(!d||d.head!==head||d.bodyHash!==c.bodyHash||!['addressed','not-actionable','duplicate'].includes(d.classification)||typeof d.reason!=='string'||!d.reason.trim())block('UNTRIAGED_COMMENT');
  }
  const required=new Set(['quality','qa','security','architecture',...(Array.isArray(evidence.requiredRoles)?evidence.requiredRoles:[])]);
  if(!evidence.authorActor||!Array.isArray(evidence.reviews))block('MISSING_INDEPENDENT_ROLES');
  for(const role of required){
    if(!roles.includes(role)){block('UNKNOWN_REQUIRED_ROLE');continue;}
    const list=(evidence.reviews||[]).filter(r=>r.role===role&&r.head===head);
    const r=list.at(-1);
    if(!r||!r.actor||r.actor===evidence.authorActor||!Array.isArray(r.evidence)||!r.evidence.length||r.evidence.some(item=>typeof item!=='string'||!item.trim()))block('MISSING_INDEPENDENT_ROLES');
    else if(r.result==='failed')fail('ROLE_REVIEW_FAILED');else if(r.result!=='passed')block('ROLE_REVIEW_UNVERIFIED');
  }
  if(!Array.isArray(evidence.tests)||!evidence.tests.length)block('MISSING_TEST_EVIDENCE');
  for(const t of evidence.tests||[]){if(t.head!==head||!Array.isArray(t.evidence)||!t.evidence.length||t.evidence.some(item=>typeof item!=='string'||!item.trim()))block('STALE_OR_MISSING_TEST_EVIDENCE');else if(t.result==='failed')fail('TESTS_FAILED');else if(t.result!=='passed')block('TESTS_UNVERIFIED');}
  // Owner-approved scope correction, 2026-11-15: the former cross-editor
  // setup check is not product acceptance. Real Codex hooks remain enforced
  // by the existing harness, not inferred from caller-supplied editor flags.
  const editorIntegration={owner:'Orqen',status:'not-verified',blocking:false};
  return {schemaVersion:1,head,editorIntegration,status:changes.size?'CHANGES_REQUIRED':blocked.size?'BLOCKED':'READY',changes:[...changes].sort(),blockers:[...blocked].sort(),comments:(snapshot?.comments||[]).map(c=>({id:c.id,bodyHash:c.bodyHash,url:safeUrl(c.url)})),participatingRoles:[...required].filter(role=>(evidence.reviews||[]).some(r=>r.role===role&&r.head===head&&r.result==='passed')),permission:'Advisory only; existing harness and explicit approvals remain authoritative.'};
}

export function audit(root,pr,evidenceFile=null){
  const snapshot=collect(root,pr), raw=evidenceFile?fs.readFileSync(evidenceFile,'utf8'):null;
  const result=assess(snapshot,raw?parse(raw):{});
  const directory=path.resolve(root,run(root,'git',['rev-parse','--git-path','crozztube-chatdev']));
  fs.mkdirSync(directory,{recursive:true,mode:0o700});const file=path.join(directory,`pr-audit-${snapshot.pr}.json`), temporary=file+'.'+randomUUID()+'.tmp';
  try{fs.writeFileSync(temporary,JSON.stringify({snapshot,result,evidenceDigest:raw?hash(raw):null},null,2)+'\n',{mode:0o600,flag:'wx'});fs.renameSync(temporary,file);}finally{if(fs.existsSync(temporary))fs.unlinkSync(temporary);}
  return result;
}
