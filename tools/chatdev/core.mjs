import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';

export const git = (root, args) => execFileSync('git', args, {cwd:root,encoding:'utf8',timeout:15000,maxBuffer:20000000,stdio:['ignore','pipe','pipe']}).trimEnd();
export const repository = (cwd = process.cwd()) => git(cwd,['rev-parse','--show-toplevel']);
const json = file => { try { return JSON.parse(fs.readFileSync(file,'utf8')); } catch { throw Error('Cannot read valid JSON; file contents suppressed'); } };
const requireText = (value, label) => { if(typeof value!=='string'||!value.trim()||value.length>10000) throw Error(`Invalid ${label}`); };
const requireList = (value, label) => { if(!Array.isArray(value)||value.some(item=>typeof item!=='string'||item.length>10000)) throw Error(`Invalid ${label}`); };

export function authoritative(root) {
  const plan=json(path.join(root,'harness/plan.json')), progress=json(path.join(root,'harness/progress.json'));
  if(plan.schemaVersion!==1||progress.schemaVersion!==1||!Array.isArray(plan.steps)||!Array.isArray(progress.steps)||plan.steps.length!==progress.steps.length) throw Error('Invalid authoritative harness state');
  plan.steps.forEach((s,i)=>{const p=progress.steps[i];if(s.id!==i+1||p.id!==s.id||!['accepted','active','pending'].includes(p.status)||s.id<progress.activeStep&&p.status!=='accepted'||s.id>progress.activeStep&&p.status!=='pending')throw Error('Invalid authoritative step sequence');});
  const step=plan.steps.find(s=>s.id===progress.activeStep), record=progress.steps.find(s=>s.id===progress.activeStep);
  if(!step||!record||record.status==='pending')throw Error('No active harness step');
  return {plan,progress,step,record};
}
export function roles(root) {
  const raw=json(path.join(root,'harness/chatdev/roles.json'));
  const list=Array.isArray(raw)?raw:raw.roles;
  if(!Array.isArray(list)||list.length!==14||new Set(list.map(r=>r.id)).size!==14)throw Error('Exactly 14 unique roles are required');
  for(const r of list){requireText(r.id,'role id');requireText(r.responsibility,'role responsibility');for(const k of ['inputs','outputs','quality','handoff']) { if(Array.isArray(r[k])) requireList(r[k],k);else requireText(r[k],k); }}
  return list;
}
export function sourceDigest(root) {
  const h=createHash('sha256');h.update(git(root,['rev-parse','HEAD']));
  const files=git(root,['ls-files','-z','--cached','--others','--exclude-standard']).split('\0').filter(Boolean);
  for(const file of [...new Set(files)].sort()) {
    h.update(file+'\0');const absolute=path.join(root,file);
    if(!fs.existsSync(absolute)){h.update('deleted');continue;}
    const stat=fs.lstatSync(absolute);h.update(String(stat.mode)+'\0');if(stat.isSymbolicLink())h.update(fs.readlinkSync(absolute));else if(stat.isFile())h.update(fs.readFileSync(absolute));
  }
  return h.digest('hex');
}
export const statePath=root=>path.resolve(root,git(root,['rev-parse','--git-path','crozztube-chatdev/state.json']));
export function state(root) {
  const file=statePath(root);if(!fs.existsSync(file))throw Error('Run setup first');
  const value=json(file);if(value.schemaVersion!==1||!Number.isSafeInteger(value.revision)||!Array.isArray(value.tasks))throw Error('Invalid local memory');return value;
}
function atomic(file,value){const temporary=file+'.'+randomUUID()+'.tmp';try{fs.writeFileSync(temporary,JSON.stringify(value,null,2)+'\n',{mode:0o600,flag:'wx'});fs.renameSync(temporary,file);}finally{if(fs.existsSync(temporary))fs.unlinkSync(temporary);}}
function locked(root,action){
  const file=statePath(root);fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});const lock=file+'.lock';
  try{fs.mkdirSync(lock,{mode:0o700});}catch{throw Error('Memory is locked; do not overwrite another writer. Inspect interrupted-session ownership before manual recovery.');}
  try {fs.writeFileSync(path.join(lock,'owner.json'),JSON.stringify({pid:process.pid,startedAt:new Date().toISOString()}),{mode:0o600});return action(file);}finally{fs.rmSync(lock,{recursive:true});}
}
export function setup(root){authoritative(root);roles(root);return locked(root,file=>{if(fs.existsSync(file))return state(root);const value={schemaVersion:1,revision:0,tasks:[]};atomic(file,value);return value;});}
export function showTask(root,id){validate(root);const task=state(root).tasks.find(t=>t.id===id);if(!task)throw Error('Unknown task');return {task,advisory:'Role review is not harness acceptance'};}
export function suggestRoles(root,files){
  requireList(files,'routing paths');const selected=new Set(['orchestrator','quality']);
  for(const file of files){
    if(/\.(md|txt)$/.test(file))selected.add('documentation');
    else {selected.add('engineer');selected.add('qa');}
    if(/^(app|routes|database)\//.test(file)){selected.add('architecture');selected.add('analyst');}
    if(/auth|upload|provider|storage|publish|billing|permission|secret|config/i.test(file))selected.add('security');
    if(/^(resources|public)\//.test(file)){selected.add('ux');selected.add('localization');}
    if(/\.(svg|png|webp|jpe?g)$/.test(file))selected.add('assets');
    if(/^lang\//.test(file))selected.add('localization');
    if(/queue|cache|query|index|redis/i.test(file))selected.add('performance');
    if(/^(docker|database)\/|Dockerfile|compose\./.test(file))selected.add('infrastructure');
    if(/^(\.github|\.cursor|\.codex|tools|harness)\//.test(file)){selected.add('repository');selected.add('security');}
  }
  const registered=new Set(roles(root).map(r=>r.id));return {roles:[...selected].filter(id=>registered.has(id)),mode:'Suggested logical roles; not spawned processes',harnessPermission:false};
}
const taskFields=['id','objective','step','scope','author','owner','reviewers','files','risks','dependencies','acceptance','checks','approvals','openIssues','nextAction'];
function validateTask(root,t,currentOnly=true){
  if(!t||typeof t!=='object'||Array.isArray(t)||Object.keys(t).some(k=>!taskFields.includes(k)))throw Error('Unknown task fields');
  for(const key of ['id','objective','author','owner','nextAction'])requireText(t[key],key);
  if(!/^[a-z0-9][a-z0-9-]{0,79}$/.test(t.id))throw Error('Invalid task id');
  const {step,plan}=authoritative(root);if(!Number.isInteger(t.step)||!plan.steps.some(s=>s.id===t.step)||t.step>step.id||(currentOnly&&t.step!==step.id))throw Error('Task must reference the current harness step; historical records may only reference known earlier steps');
  if(!['active-step','tooling'].includes(t.scope))throw Error('Explicit task scope required');
  const ids=roles(root).map(r=>r.id);if(!ids.includes(t.owner))throw Error('Unknown owner role');
  for(const key of ['reviewers','files','risks','dependencies','acceptance','approvals','openIssues'])requireList(t[key],key);
  if(!t.acceptance.length||!t.reviewers.length||t.reviewers.some(r=>!ids.includes(r)))throw Error('Acceptance and valid reviewers required');
  if(t.files.some(f=>path.isAbsolute(f)||f.split(/[\\/]/).includes('..')||/(^|\/)\.env($|\.)|\.(pem|key)$/i.test(f)))throw Error('Unsafe task path');
  if(!Array.isArray(t.checks)||t.checks.some(c=>!c||Object.keys(c).some(k=>!['command','result','evidence'].includes(k))||!['passed','failed','not-verified'].includes(c.result)||typeof c.command!=='string'||typeof c.evidence!=='string'))throw Error('Invalid actual check evidence');
  // Do not ingest credentials, free-form transcripts or environment dumps.
  if(/-----BEGIN .*PRIVATE KEY-----|(?:api[_-]?key|auth[_-]?token|password|secret_access_key)\s*[=:]\s*\S+/i.test(JSON.stringify(t)))throw Error('Potential secret: keep credentials out of memory');
}
function change(root,revision,fn){return locked(root,file=>{const current=state(root);if(current.revision!==revision)throw Error('Revision conflict; reread shared memory');const next=structuredClone(current);fn(next);next.revision++;atomic(file,next);return next;});}
export function createTask(root,input,revision){validateTask(root,input);return change(root,revision,s=>{if(s.tasks.some(t=>t.id===input.id))throw Error('Task already exists');s.tasks.push({...input,reviews:[],updatedAt:new Date().toISOString()});});}
export function updateTask(root,input,revision){validateTask(root,input);return change(root,revision,s=>{const index=s.tasks.findIndex(t=>t.id===input.id);if(index<0)throw Error('Unknown task');s.tasks[index]={...input,reviews:[],updatedAt:new Date().toISOString()};});}
export function reviewTask(root,id,review,revision){
  if(!review||Object.keys(review).some(k=>!['actor','role','decision','evidence'].includes(k)))throw Error('Invalid review fields');
  for(const k of ['actor','role'])requireText(review[k],k);requireList(review.evidence,'review evidence');
  if(/-----BEGIN .*PRIVATE KEY-----|(?:api[_-]?key|auth[_-]?token|password|secret_access_key)\s*[=:]\s*\S+/i.test(JSON.stringify(review)))throw Error('Potential secret in review');
  if(!['approved','rejected'].includes(review.decision)||!review.evidence.length)throw Error('Explicit review decision and evidence required');
  return change(root,revision,s=>{const t=s.tasks.find(t=>t.id===id);if(!t)throw Error('Unknown task');if(t.step!==authoritative(root).step.id)throw Error('Historical tasks are read-only');if(review.actor===t.author||!t.reviewers.includes(review.role)||review.role===t.owner)throw Error('Independent reviewer required');
    if(review.decision==='approved'&&(t.openIssues.length||t.approvals.length||!t.checks.length||t.checks.some(c=>c.result!=='passed')))throw Error('Unresolved issues, permissions or checks prevent approval');
    t.reviews=t.reviews.filter(r=>r.role!==review.role);t.reviews.push({...review,sourceDigest:sourceDigest(root),reviewedAt:new Date().toISOString()});
  });
}
export function validate(root){const s=state(root);roles(root);authoritative(root);const digest=sourceDigest(root);for(const t of s.tasks){const {reviews,updatedAt,...input}=t;validateTask(root,input,false);if(!Array.isArray(reviews)||reviews.some(r=>!r||r.actor===t.author||r.role===t.owner||!t.reviewers.includes(r.role)||!['approved','rejected'].includes(r.decision)||!Array.isArray(r.evidence)||!r.evidence.length||r.evidence.some(e=>typeof e!=='string')||!/^([a-f0-9]{64})$/.test(r.sourceDigest)||typeof r.reviewedAt!=='string'))throw Error('Invalid reviews');}return {revision:s.revision,tasks:s.tasks.map(t=>({id:t.id,reviewStatus:t.reviews.length&&t.reviewers.every(role=>t.reviews.some(r=>r.role===role&&r.decision==='approved'&&r.sourceDigest===digest))?'reviewed':'open'})),harnessGate:'not-granted: only the existing harness can verify, publish or complete'};}
export function context(root){const a=authoritative(root);return {repository:root,branch:git(root,['branch','--show-current']),head:git(root,['rev-parse','HEAD']),workingTree:git(root,['status','--short']),authoritative:{plan:'harness/plan.json',progress:'harness/progress.json',activeStep:a.step.id,title:a.step.title,recordStatus:a.record.status,publication:'not inferred; run the harness status command'},references:['AGENTS.md','PRODUCT.md','DESIGN.md','HANDOFF.md','harness/README.md','harness/chatdev/CORE.md','harness/chatdev/facts.md','harness/chatdev/decisions.md'],roles:roles(root).map(r=>r.id),memory:fs.existsSync(statePath(root))?{...validate(root),path:statePath(root),readTask:'node tools/chatdev/cli.mjs show task-id'}:'not initialized',nextAction:'Read authoritative sources and task evidence; no role grants harness permission.'};}
