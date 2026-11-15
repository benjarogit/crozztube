#!/usr/bin/env node
import fs from 'node:fs';
import { audit } from './pr-audit.mjs';
import { reconcile } from './github-lifecycle.mjs';
import { manager } from './project-manager.mjs';
import { installSkill } from './install-skill.mjs';
import { repository, setup, context, showTask, suggestRoles, createTask, updateTask, reviewTask, validate } from './core.mjs';
const [command='status',...args]=process.argv.slice(2);
const inputJson=file=>{try{return JSON.parse(fs.readFileSync(file,'utf8'));}catch{throw Error('Cannot read valid input JSON; contents suppressed');}};
try {
  const root=repository();let result;
  if(command==='install-skill'){if(args.length)throw Error('Usage: install-skill');result=installSkill();}
  else if(command==='setup')result=setup(root);
  else if(command==='status'||command==='context')result=context(root);
  else if(command==='project-manager')result=manager(root);
  else if(command==='pr-audit'||command==='github-sync'){
    if(args.length<1||args.length>2)throw Error('Usage: '+command+' pr-number [evidence.json]');
    const evidence=args[1]?inputJson(args[1]):{};
    result=audit(root,args[0],args[1]||null);
    result.github=reconcile(root,args[0],evidence,result,command==='pr-audit',undefined,()=>{if(args[1]&&JSON.stringify(inputJson(args[1]))!==JSON.stringify(evidence))throw Error('Evidence file changed; refresh before mutation');return audit(root,args[0],args[1]||null);});
    result.projectManagement=manager(root,result);
  }
  else if(command==='validate')result=validate(root);
  else if(command==='show'){if(args.length!==1)throw Error('Usage: show task-id');result=showTask(root,args[0]);}
  else if(command==='route')result=suggestRoles(root,args);
  else if(command==='create'||command==='update'){
    if(args.length!==2)throw Error('Usage: create|update input.json expected-revision');
    const input=inputJson(args[0]);
    result=(command==='create'?createTask:updateTask)(root,input,Number(args[1]));
  }else if(command==='review'){
    if(args.length!==3)throw Error('Usage: review task-id review.json expected-revision');
    result=reviewTask(root,args[0],inputJson(args[1]),Number(args[2]));
  }else throw Error('Commands: install-skill, setup, status, context, show, route, create, update, review, validate, pr-audit, github-sync, project-manager');
  // Mutations report identifiers only: do not echo submitted task text.
  console.log(JSON.stringify(['create','update','review','setup'].includes(command)?{revision:result.revision,tasks:result.tasks.map(t=>t.id)}:result,null,2));
}catch(error){console.error('ChatDev: '+error.message);process.exitCode=1;}
