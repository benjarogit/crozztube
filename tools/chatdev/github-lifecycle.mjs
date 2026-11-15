import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { authoritative, git, state, validate } from './core.mjs';
import { manager } from './project-manager.mjs';

export const bodyHash = body => createHash('sha256').update(String(body ?? '')).digest('hex');
const number = n => Number.isSafeInteger(n) && n > 0;
export function safeBinding(binding) {
  const text=JSON.stringify(binding);
  if(/https?:\/\/[^\s"<>]*[?]|https?:\/\/[^\s/"<>]*@|\bbearer\s+\S+/i.test(text)) throw Error('Potential sensitive publication input; contents suppressed');
  if(text.length>50000 || /-----BEGIN .*PRIVATE KEY-----|(?:api[_-]?key|auth[_-]?token|password|secret_access_key|authorization|bearer)\s*[=:]\s*\S+|https?:\/\/[^\s"<>]*[?&](?:token|key|secret|signature|credential|password|auth)[^=\s]*=/i.test(text)) throw Error('Potential sensitive publication input; contents suppressed');
  if(!/^[a-z0-9][a-z0-9-]{0,79}$/.test(binding?.taskId ?? '') || !Array.isArray(binding.criteria) || binding.criteria.some(c=>typeof c?.text!=='string' || !c.text.trim() || !['passed','not-verified'].includes(c.result) || !Array.isArray(c.evidence) || c.evidence.some(e=>typeof e!=='string'||!e.trim()))) throw Error('Invalid mapped acceptance criteria');
}
export function dependenciesFor(prs,current,repository,baseInPlan) {
  const parents=prs.filter(p=>p.number!==current.number && p.head?.ref===current.base?.ref && p.head?.repo?.full_name===repository);
  const blocked=parents.filter(p=>!p.merged_at).map(p=>p.number);
  if(!baseInPlan)blocked.push('BASE_NOT_ON_PUBLISHED_PLAN');
  return blocked;
}
const marker = '<!-- crozztube-chatdev:work-package-status:v1 -->';

// Only explicit evidence mappings are eligible for writes. Remote prose is data,
// never instructions or an inferred grant of acceptance/merge permission.
export function issueDecision(issue, binding, task, review, audit, published, dependencies) {
  const blockers = [];
  if (issue.pull_request || !number(issue.number)) throw Error('Expected an issue, never a PR');
  if (!binding || binding.number !== issue.number || binding.bodyHash !== bodyHash(issue.body)) blockers.push('ISSUE_CHANGED_OR_UNBOUND');
  if (!task || binding?.taskId !== task.id || review !== 'reviewed') blockers.push('TASK_NOT_INDEPENDENTLY_REVIEWED');
  if (!task?.checks?.length || task.checks.some(c => c.result !== 'passed')) blockers.push('TEST_EVIDENCE_INCOMPLETE');
  if (task?.openIssues?.length || task?.approvals?.length) blockers.push('TASK_NOT_ACCEPTED');
  const criteria = binding?.criteria;
  if (!Array.isArray(criteria) || !criteria.length || JSON.stringify(criteria.map(c => c.text)) !== JSON.stringify(task?.acceptance)
    || criteria.some(c => c.result !== 'passed' || !Array.isArray(c.evidence) || !c.evidence.length || c.evidence.some(e => typeof e !== 'string' || !e.trim()))) blockers.push('ACCEPTANCE_INCOMPLETE');
  if (audit.status !== 'READY') blockers.push('PR_NOT_READY');
  if (!published) blockers.push('HEAD_NOT_CLEAN_AND_PUBLISHED');
  if (dependencies.length) blockers.push('PR_DEPENDENCY_UNMERGED');
  return {number: issue.number, action: issue.state === 'closed' ? 'already-closed' : blockers.length ? 'keep-open' : 'close', blockers};
}

// Small injectable transport permits deterministic mutation/failure tests. No
// token values, raw remote bodies or CLI errors are returned to the transcript.
export function transport(root) {
  return (endpoint, method = 'GET', data = null, paginate = false) => {
    try {
      const args = ['api', ...(paginate ? ['--paginate', '--slurp'] : []), endpoint, '--method', method, ...(data ? ['--input', '-'] : [])];
      return JSON.parse(execFileSync('gh', args, {cwd: root, encoding: 'utf8', input: data ? JSON.stringify(data) : undefined, timeout: 90000, maxBuffer: 20000000, stdio: ['pipe','pipe','pipe']}));
    } catch { throw Error('GitHub lifecycle API failed; response suppressed'); }
  };
}

export function applyIssue(api, repo, original, decision, commentBody, login, beforeMutation = () => {}) {
  if(typeof login!=='string'||! /^[a-z0-9-]+(?:\[bot\])?$/i.test(login)) throw Error('Authenticated comment owner required');
  if (decision.action === 'already-closed') return {number: original.number, action: 'already-closed'};
  const base = `repos/${repo}/issues/${original.number}`;
  const fresh = () => {
    const current = api(base);
    if (current.pull_request || current.state !== original.state || current.updated_at !== original.updated_at || bodyHash(current.body) !== bodyHash(original.body)) throw Error('Issue changed during reconciliation; refresh before retry');
    return current;
  };
  fresh();
  const comments = api(`${base}/comments?per_page=100`, 'GET', null, true).flat();
  const owned = comments.filter(c => c.user?.login === login && c.body?.startsWith(marker));
  if (owned.length > 1) throw Error('Ambiguous managed comments; preserve all comments');
  fresh();
  let updated = false;
  if (owned[0]?.body !== commentBody) {
    beforeMutation('comment');
    fresh();
    if (owned.length) api(`repos/${repo}/issues/comments/${owned[0].id}`, 'PATCH', {body: commentBody});
    else api(`${base}/comments`, 'POST', {body: commentBody});
    updated = true;
  }
  // Posting a comment changes updated_at; take a new baseline, while still
  // checking the user's body/state. GitHub provides no atomic issue-body CAS.
  const after = api(base);
  if (after.pull_request || after.state !== original.state || bodyHash(after.body) !== bodyHash(original.body)) throw Error('Issue changed after status update; not closed');
  const verified = api(`${base}/comments?per_page=100`, 'GET', null, true).flat().filter(c => c.user?.login === login && c.body?.startsWith(marker));
  if (verified.length !== 1 || verified[0].body !== commentBody) throw Error('Status comment not confirmed; not closed');
  if (decision.action === 'close') {
    beforeMutation('close');
    const last = api(base);
    if (last.state !== after.state || last.updated_at !== after.updated_at || bodyHash(last.body) !== bodyHash(original.body)) throw Error('Issue changed before close; refresh before retry');
    api(base, 'PATCH', {state: 'closed', state_reason: 'completed'});
    if (api(base).state !== 'closed') throw Error('Issue closure not confirmed');
  }
  return {number: original.number, action: decision.action, comment: updated ? 'updated-and-confirmed' : 'unchanged'};
}

export function reconcile(root, pr, evidence, audit, apply = false, api = transport(root), refreshAudit = null) {
  if (!number(Number(pr))) throw Error('Expected a PR number');
  const repository = JSON.parse(execFileSync('gh', ['repo','view','--json','nameWithOwner'], {cwd:root, encoding:'utf8'})).nameWithOwner;
  if (!/^[\w.-]+\/[\w.-]+$/.test(repository)) throw Error('Invalid repository');
  const origin=git(root,['remote','get-url','origin']);
  const originRepo=origin.match(/^(?:https:\/\/github\.com\/|git@github\.com:)([\w.-]+\/[\w.-]+?)(?:\.git)?$/)?.[1];
  if(originRepo !== repository) throw Error('GitHub repository differs from origin');
  const base = `repos/${repository}`, head = git(root, ['rev-parse','HEAD']);
  const issues = api(`${base}/issues?state=open&per_page=100`, 'GET', null, true).flat().filter(i => !i.pull_request);
  const prs = api(`${base}/pulls?state=all&per_page=100`, 'GET', null, true).flat();
  const current = prs.find(p => p.number === Number(pr));
  if (!current || current.head?.repo?.full_name !== repository || current.base?.repo?.full_name !== repository || current.head?.sha !== head || audit.head !== head || (evidence?.githubIssues?.length && evidence?.head !== head)) throw Error('PR/evidence no longer matches current HEAD');
  // Stacked PRs depend on the PR owning their base branch. A closed, unmerged
  // predecessor does not satisfy this dependency. Never merge or retarget here.
  const planBranch=authoritative(root).plan.branch;
  const dependencyState=(list,p)=>{
    if(!/^[a-f0-9]{40,64}$/.test(p.base?.sha ?? ''))throw Error('Missing base identity');
    const comparison=api(`${base}/compare/${encodeURIComponent(planBranch)}...${p.base.sha}`);
    const inPlan=['identical','behind'].includes(comparison.status);
    return dependenciesFor(list,p,repository,inPlan);
  };
  const dependencies=dependencyState(prs,current);
  const branch = git(root, ['branch','--show-current']);
  if(current.head?.ref!==branch)throw Error('PR is not the working branch');
  const remote = git(root, ['ls-remote','--heads','origin',`refs/heads/${branch}`]).split(/\s/)[0];
  const published = !git(root, ['status','--porcelain']) && remote === head;
  const journal = state(root), reviews = validate(root).tasks;
  const bindings = evidence.githubIssues ?? [];
  if (!Array.isArray(bindings) || bindings.some(b => !number(b?.number)) || new Set(bindings.map(b => b.number)).size !== bindings.length) throw Error('Invalid or duplicate explicit issue bindings');
  const decisions = [];
  for (const binding of bindings) {
    const issue = issues.find(i => i.number === binding.number) ?? api(`${base}/issues/${binding.number}`);
    safeBinding(binding);
    const task = journal.tasks.find(t => t.id === binding.taskId);
    const taskDependencies=manager(root,audit).priorities.find(t=>t.id===task?.id)?.blockers ?? ['TASK_NOT_CURRENT'];
    const decision = issueDecision(issue, binding, task, reviews.find(r => r.id === binding.taskId)?.reviewStatus, audit, published, [...dependencies,...taskDependencies]);
    const criteria = binding.criteria ?? [];
    const body = `${marker}\nWork package: ${binding.taskId}; published source: ${head}.\n\n${criteria.map(c => `- [${c.result === 'passed' ? 'x' : ' '}] ${c.text}\n  Evidence: ${(c.evidence ?? []).join('; ')}`).join('\n')}\n\nDecision: ${decision.action}. Remaining: ${decision.blockers.join(', ') || 'none'}.\nPR: https://github.com/${repository}/pull/${pr}. Publication on the working branch is not a merge, deployment or harness step acceptance.`;
    if (apply) {
      if(binding.bodyHash!==bodyHash(issue.body) || !task || JSON.stringify(binding.criteria.map(c=>c.text))!==JSON.stringify(task.acceptance)) throw Error('Issue/task binding changed or incomplete; no mutation');
      if (typeof refreshAudit !== 'function') throw Error('Fresh audit required before mutations');
      const beforeMutation=()=>{
        const refreshed=refreshAudit(), latestPr=api(`${base}/pulls/${pr}`);
        const shape=r=>JSON.stringify({head:r.head,status:r.status,blockers:r.blockers,changes:r.changes});
        if(shape(refreshed)!==shape(audit) || git(root,['rev-parse','HEAD'])!==head || git(root,['status','--porcelain']) || state(root).revision!==journal.revision) throw Error('Evidence changed; refresh before mutation');
        if(latestPr.head?.sha!==head || latestPr.base?.ref!==current.base?.ref || latestPr.base?.sha!==current.base?.sha || latestPr.head?.repo?.full_name!==repository || latestPr.state!==current.state || latestPr.draft!==current.draft) throw Error('Remote PR changed; refresh before mutation');
        const remoteHead=git(root,['ls-remote','--heads','origin',`refs/heads/${branch}`]).split(/\s/)[0];
        if(remoteHead!==head) throw Error('Publication changed; refresh before mutation');
        if(JSON.stringify(validate(root).tasks)!==JSON.stringify(reviews)) throw Error('Task review changed; refresh before mutation');
        const refreshedPrs=api(`${base}/pulls?state=all&per_page=100`,'GET',null,true).flat();
        const newDependencies=dependencyState(refreshedPrs,latestPr);
        if(JSON.stringify(newDependencies)!==JSON.stringify(dependencies)) throw Error('Dependencies changed; refresh before mutation');
      };
      decision.publication = applyIssue(api, repository, issue, decision, body, api('user').login, beforeMutation);
    }
    decisions.push(decision);
  }
  return {repository, head, pr:Number(pr), openIssues:issues.map(i => i.number), unmappedOpenIssues:issues.filter(i => !bindings.some(b => b.number === i.number)).map(i => i.number), dependencies, pullRequests:prs.filter(p=>p.state==='open').map(p=>({number:p.number,head:p.head?.sha,base:p.base?.ref,draft:p.draft,state:p.state})), decisions, mode: apply ? 'explicit-mappings-applied' : 'read-only', permission:'No PR merge/closure, retarget, harness advancement or deployment.'};
}
