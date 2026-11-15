import fs from 'node:fs';
import path from 'node:path';
import { authoritative, git, roles, state, statePath, validate } from './core.mjs';
import { validBaseIdentity, sameBaseIdentity, hasCurrentBaseTip, withReconciliation } from './pr-audit.mjs';

/** Read-only coordination. This reports evidence; it never grants harness permission. */
export function manager(root,prResult=null) {
  const authority=authoritative(root), registry=roles(root), head=git(root,['rev-parse','HEAD']);
  const file=statePath(root), exists=fs.existsSync(file);
  const journal=exists?state(root):{revision:0,tasks:[]};
  const evidence=exists?validate(root):{tasks:[]};
  const reviewById=new Map(evidence.tasks.map(t=>[t.id,t.reviewStatus]));
  const byId=new Map(journal.tasks.map(t=>[t.id,t]));
  if(byId.size!==journal.tasks.length)throw Error('Duplicate task IDs prevent coordination');
  const blockers=[];
  const cycleIds=new Set(), visited=new Set(), stack=[], ordered=[];
  const current=journal.tasks.filter(t=>t.step===authority.step.id);
  const visit=t=>{
    const at=stack.indexOf(t.id);
    if(at>=0){for(const id of stack.slice(at))cycleIds.add(id);return;}
    if(visited.has(t.id))return;
    stack.push(t.id);
    for(const dep of t.dependencies){const other=byId.get(dep);if(other&&other.step===authority.step.id)visit(other);}
    stack.pop();visited.add(t.id);ordered.push(t);
  };
  for(const t of current)visit(t);
  const reports=new Map();
  for(const t of ordered){
    const blocked=[];
    if(cycleIds.has(t.id))blocked.push('DEPENDENCY_CYCLE');
    for(const dependency of t.dependencies){
      const other=byId.get(dependency);
      if(!other)blocked.push(`UNKNOWN_DEPENDENCY:${dependency}`);
      else if(other.step===authority.step.id&&(reviewById.get(dependency)!=='reviewed'||other.approvals.length||other.openIssues.length||other.checks.some(c=>c.result!=='passed')||reports.get(dependency)?.blockers.length))blocked.push(`DEPENDENCY_PENDING:${dependency}`);
    }
    if(t.approvals.length)blocked.push('APPROVAL_REQUIRED');
    if(t.openIssues.length)blocked.push('OPEN_ISSUES');
    const failed=t.checks.some(c=>c.result==='failed');
    const unchecked=t.checks.some(c=>c.result==='not-verified');
    let status=blocked.length?'blocked':failed?'needs-fix':unchecked?'needs-tests':reviewById.get(t.id)==='reviewed'?'reviewed-awaiting-harness':t.checks.length?'needs-review':'ready-to-resume';
    // Reviews are advisory and must never turn a task into harness completion.
    const report={id:t.id,step:t.step,scope:t.scope,owner:t.owner,objective:t.objective,status,dependencies:[...t.dependencies],blockers:blocked,approvals:[...t.approvals],openIssues:[...t.openIssues],reviewStatus:reviewById.get(t.id),checks:t.checks.map(c=>({command:c.command,result:c.result})),nextAction:t.nextAction};
    reports.set(t.id,report);
  }
  const historical=journal.tasks.filter(t=>t.step<authority.step.id).map(t=>({id:t.id,step:t.step,status:'historical',owner:t.owner,acceptedStep:authority.progress.steps.find(s=>s.id===t.step)?.status==='accepted'}));
  let pr=prResult, auditState='supplied', storedSnapshot;
  if(!pr){
    const directory=path.dirname(file);
    const snapshots=fs.existsSync(directory)?fs.readdirSync(directory).filter(name=>/^pr-audit-[1-9]\d*\.json$/.test(name)):[];
    const items=[];
    for(const name of snapshots){try{const record=JSON.parse(fs.readFileSync(path.join(directory,name),'utf8'));items.push({...record,auditPr:Number(name.match(/\d+/)[0])});}catch{blockers.push('PR_AUDIT_INVALID');}}
    // Prefer a current-head result. Never promote a stale READY snapshot.
    items.sort((a,b)=>String(b.snapshot?.collectedAt||'').localeCompare(String(a.snapshot?.collectedAt||'')));
    const candidates=items.filter(item=>item.result?.head===head);
    if(new Set(candidates.map(item=>item.auditPr)).size>1){
      blockers.push('PR_AUDIT_AMBIGUOUS');
      auditState='ambiguous';
      pr=null;
    }else{
      const selected=candidates[0]||items[0];
      pr=selected?.result||null;storedSnapshot=selected?.snapshot;
      auditState=pr?'stored snapshot; refresh before publication':'missing';
    }
  }
  if(pr)pr=withReconciliation(pr);
  if(pr&&(!validBaseIdentity(pr)||!prResult&&!sameBaseIdentity(pr,storedSnapshot))){
    blockers.push('PR_AUDIT_BASE_UNBOUND');
    pr={...pr,status:pr.status==='CHANGES_REQUIRED'?'CHANGES_REQUIRED':'BLOCKED'};
  }
  if(pr&&(!hasCurrentBaseTip(pr)||!prResult&&(!hasCurrentBaseTip(storedSnapshot)||storedSnapshot?.complete?.baseTipStable!==true||pr.baseTipSha!==storedSnapshot.baseTipSha))){
    blockers.push('PR_AUDIT_BASE_TIP_UNBOUND');
    pr={...pr,status:pr.status==='CHANGES_REQUIRED'?'CHANGES_REQUIRED':'BLOCKED'};
  }
  if(!pr){if(auditState!=='ambiguous')blockers.push('PR_AUDIT_MISSING');}
  else if(pr.head!==head)blockers.push('PR_AUDIT_STALE');
  else if(pr.status!=='READY')blockers.push(...(Array.isArray(pr.changes)?pr.changes:[]),...(Array.isArray(pr.blockers)?pr.blockers:[]),`PR_${['BLOCKED','CHANGES_REQUIRED'].includes(pr.status)?pr.status:'UNKNOWN'}`);
  if(!exists)blockers.push('TASK_JOURNAL_NOT_INITIALIZED');
  const priorities=ordered.map(t=>reports.get(t.id));
  const taskBlockers=priorities.flatMap(t=>t.blockers.map(code=>({task:t.id,code})));
  let nextAction;
  if(blockers.includes('PR_AUDIT_AMBIGUOUS'))nextAction={action:'select-current-pr',role:'repository',instruction:'Select the intended PR explicitly and run a fresh pr-audit; pass that result to the project manager. Do not infer PR identity from a shared head.'};
  else if(blockers.length)nextAction={action:'resolve-current-gates',role:blockers.some(b=>/RUNTIME/.test(b))?'qa':'repository',instruction:'Resolve the reported current PR/runtime evidence gaps; preserve the active step and existing work.'};
  else {
    const actionable=priorities.find(t=>!['blocked','reviewed-awaiting-harness'].includes(t.status));
    if(actionable)nextAction={action:'resume-existing-task',task:actionable.id,role:actionable.status==='needs-tests'?'qa':actionable.status==='needs-review'?'quality':actionable.owner,instruction:actionable.nextAction};
    else if(taskBlockers.length)nextAction={action:'resolve-current-task-blockers',role:'project-manager',instruction:'Resolve dependencies or obtain the listed approvals before resuming affected tasks.'};
    else nextAction={action:'inspect-current-step',role:'project-manager',instruction:'Read the current step acceptance criteria and existing work. Ask the harness for publication status; do not start a later step.'};
  }
  return {schemaVersion:1,role:'project-manager',head,activeStep:{id:authority.step.id,title:authority.step.title,recordStatus:authority.record.status,authority:'harness/plan.json + harness/progress.json'},mode:'Read-only coordination; logical role, not proof of a spawned process',github:prResult?.github ?? {status:'not-refreshed',nextAction:'Run pr-audit after every work package; github-sync offers a read-only preview.'},registeredRoles:registry.map(r=>r.id),journalRevision:journal.revision,priorities,historical,blockers:[...new Set(blockers)],taskBlockers,prAudit:pr?{schemaVersion:pr.schemaVersion,baseSource:pr.baseSource,reportedBaseSha:pr.reportedBaseSha,head:pr.head,baseSha:pr.baseSha,baseRef:pr.baseRef,baseRepository:pr.baseRepository,baseTipSha:pr.baseTipSha,status:pr.status,source:auditState}:null,nextAction,permission:'Advisory only. No begin, completion, merge, deployment or approval is performed.'};
}
