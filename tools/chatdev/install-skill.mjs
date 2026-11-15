import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const files=['SKILL.md','agents/openai.yaml'];
const digest=value=>createHash('sha256').update(value).digest('hex');
const exists=p=>{try{return fs.lstatSync(p);}catch(e){if(e.code==='ENOENT')return null;throw e;}};
function plainAncestors(p){for(let x=p;;x=path.dirname(x)){const s=exists(x);if(s&&(s.isSymbolicLink()||!s.isDirectory()))throw Error('Unsafe skill directory: '+x);if(x===path.dirname(x))break;}}
export function installSkill({home=os.homedir(),source=fileURLToPath(new URL('./skill/',import.meta.url))}={}){
  const target=path.join(home,'.agents/skills/chatdev');
  for(const root of ['.cursor/skills','.codex/skills'])if(exists(path.join(home,root,'chatdev')))throw Error('Existing alternate ChatDev skill; reconcile it manually before installation');
  plainAncestors(target);
  fs.mkdirSync(path.dirname(target),{recursive:true});
  const lock=target+'.install-lock';
  try{fs.mkdirSync(lock);}catch(e){if(e.code==='EEXIST')throw Error('Skill installer locked; confirm stopped writer before manual recovery');throw e;}
  let stage=null,backup=null,published=false;
  try{
    const receipt=path.join(target,'.crozztube-managed.json');
    let old=null;
    if(exists(target)){
      const stat=exists(receipt);
      if(!stat||stat.isSymbolicLink()||!stat.isFile())throw Error('Existing unmanaged ChatDev skill; refusing to overwrite');
      try{old=JSON.parse(fs.readFileSync(receipt,'utf8'));}catch{throw Error('Invalid skill installation receipt');}
      if(old.owner!=='crozztube-chatdev'||old.schemaVersion!==1)throw Error('Unrecognized skill installation receipt');
      for(const file of files){const p=path.join(target,file);plainAncestors(path.dirname(p));const s=exists(p);if(!s||s.isSymbolicLink()||!s.isFile()||digest(fs.readFileSync(p))!==old.hashes?.[file])throw Error('Installed ChatDev skill was modified; refusing to overwrite');}
    }
    const content=Object.fromEntries(files.map(file=>[file,fs.readFileSync(path.join(source,file))]));
    const hashes=Object.fromEntries(files.map(file=>[file,digest(content[file])]));
    if(old&&files.every(file=>old.hashes[file]===hashes[file]))return {status:'unchanged',path:target};
    stage=fs.mkdtempSync(path.join(lock,'prepared-'));
    if(old)fs.cpSync(target,stage,{recursive:true,dereference:false,verbatimSymlinks:true});
    fs.mkdirSync(path.join(stage,'agents'),{recursive:true});
    for(const file of files)fs.writeFileSync(path.join(stage,file),content[file],{mode:0o600});
    fs.writeFileSync(path.join(stage,'.crozztube-managed.json'),JSON.stringify({owner:'crozztube-chatdev',schemaVersion:1,hashes},null,2)+'\n',{mode:0o600});
    // Prepare every byte before replacing the old directory. Keep the backup
    // inside the lock for manual recovery if this process is forcibly killed.
    if(old){backup=path.join(lock,'previous');fs.renameSync(target,backup);}
    try{fs.renameSync(stage,target);stage=null;published=true;}catch(error){if(backup){fs.renameSync(backup,target);backup=null;}throw error;}
    return {status:old?'updated':'installed',path:target};
  }finally{
    // A failed rollback must retain the backup and lock rather than erase data.
    if(!backup||published)fs.rmSync(lock,{recursive:true,force:true});
  }
}
