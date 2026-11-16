import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { seriesScope,seriesHistory,verifySeries,publishSeries } from '../harness/series-maintenance.mjs';
import { setMode,localPath } from '../harness/workflow.mjs';
const git=(root,...args)=>execFileSync('git',args,{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();
function fixture(fn){
  const home=fs.mkdtempSync(path.join(os.tmpdir(),'series-gate-')), root=path.join(home,'repo'), remote=path.join(home,'remote.git');fs.mkdirSync(root);
  const write=(name,body)=>{const target=path.join(root,name);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,body);};
  try{
    git(home,'init','--bare',remote);git(root,'init','-b','codex/chatdev-integration');git(root,'config','user.name','Synthetic series fixture');git(root,'config','user.email','fixture@example.invalid');git(root,'config','core.hooksPath',path.join(root,'.git/hooks'));git(remote,'config','core.hooksPath',path.join(remote,'hooks'));git(root,'remote','add','origin',remote);
    const plan={schemaVersion:1,branch:'main',remote:'origin',steps:Array.from({length:8},(_,i)=>({id:i+1,title:'Synthetic step '+(i+1),checks:['completion-not-executed'],...(i===7?{checkpointChecks:['harness-tests','staged-safety']}:{})})),checks:{'harness-tests':{argv:[process.execPath,'tools/test/synthetic-check.mjs','first'],timeoutMs:5000},'staged-safety':{argv:[process.execPath,'tools/test/synthetic-check.mjs','second'],timeoutMs:5000}}};
    write('harness/plan.json',JSON.stringify(plan));write('harness/progress.json',JSON.stringify({schemaVersion:1,activeStep:8,steps:Array.from({length:8},(_,i)=>({id:i+1,status:i===7?'active':'accepted'}))}));
    write('tools/test/synthetic-check.mjs',"if(process.env.SERIES_FIXTURE_FAIL)process.exit(1); console.log('Synthetic check '+process.argv[2]);\n");
    write('resources/js/main.jsx','// Synthetic existing UI\n');write('build/manifest.json','{}\n');write('AGENTS.md','Existing rules\n');write('.gitignore','build/assets/*\n');write('lang/en/english.php',"<?php return ['Existing'=>'Existing'];\n");write('lang/ru/russian.php',"<?php return ['Existing'=>'Existing'];\n");
    for(const f of ['.codex/hooks.json','.cursor/hooks.json','tools/harness/codex-hook.mjs','tools/harness/workflow.mjs','tools/harness/maintenance.mjs'])write(f,'Existing immutable fixture\n');
    git(root,'add','.');git(root,'commit','-qm','Synthetic parent');git(root,'push','-u','origin','codex/chatdev-integration');git(root,'checkout','-b','codex/series-seasons');setMode(root,'implementing');
    return fn({home,root,remote,write,plan,stage:()=>git(root,'add','.')});
  }finally{fs.rmSync(home,{recursive:true,force:true});}
}
function integrationFixture(fn){return fixture(ctx=>{
  const {root,write,stage}=ctx, oldBase=git(root,'rev-parse','HEAD');
  write('resources/js/main.jsx','// Approved synthetic feature\n');stage();git(root,'commit','-qm','Feature checkpoint');
  const feature=git(root,'rev-parse','HEAD');git(root,'push','origin','HEAD:refs/heads/codex/series-seasons');
  git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/parent-fixture.mjs','// Imported tooling only\n');stage();git(root,'commit','-qm','Published parent tooling');git(root,'push','origin','codex/chatdev-integration');
  const base=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
  const canonical=()=>git(root,'merge-tree','--write-tree',feature,base).split('\n')[0];
  const commitMerge=(tree=canonical(),parents=[feature,base])=>{const merge=git(root,'commit-tree',tree,...parents.flatMap(p=>['-p',p]),'-m','Synthetic integration');git(root,'reset','--hard',merge);return merge;};
  return fn({...ctx,oldBase,feature,base,canonical,commitMerge});
});}
// Exercise the real public scope in a child process with only its fixed
// production checkpoint replaced by the synthetic fixture's checkpoint.
// Neither the installed CLI nor public scope accepts an anchor override.
function fixturePolicy(root,feature,base,firstMerge=null,nextHead=null){
  const merge=firstMerge||git(root,'rev-list','--merges',base+'..HEAD').split('\n').filter(Boolean).at(-1);
  return {firstHead:feature,firstMerge:merge,firstParent:base,nextHead:nextHead||merge};
}
function fixtureIntegrationScope(root,anchor,policy=null){
  const module=new URL('../harness/series-maintenance.mjs',import.meta.url);
  const base=git(root,'rev-parse','origin/codex/chatdev-integration');
  policy ||= fixturePolicy(root,anchor,base);
  let source=fs.readFileSync(module,'utf8');
  source=source.replace(/const historyPolicy=Object\.freeze\(\{[^\n]+\}\);/,`const historyPolicy=Object.freeze(${JSON.stringify(policy)});`);
  source=source.replace("'./workflow.mjs'",JSON.stringify(new URL('../harness/workflow.mjs',import.meta.url).href));
  source=source.replace("'../chatdev/stable-series-base.mjs'",JSON.stringify(new URL('../chatdev/stable-series-base.mjs',import.meta.url).href));
  return JSON.parse(execFileSync(process.execPath,['--input-type=module','-e',`const m=await import('data:text/javascript;base64,${Buffer.from(source).toString('base64')}');console.log(JSON.stringify(m.seriesScope(${JSON.stringify(root)})));`],{cwd:root,encoding:'utf8',stdio:['ignore','pipe','pipe']}));
}
test('only canonical exact-head tooling integration excludes imported parent history',()=>integrationFixture(({root,feature,base,commitMerge,write,stage})=>{
  const merge=commitMerge(),history=seriesHistory(root,base,fixturePolicy(root,feature,base));
  assert.deepEqual(history,[feature]);assert(!history.includes(base));assert(!history.includes(merge));
  assert.equal(fixtureIntegrationScope(root,feature).base,base);
  assert.throws(()=>seriesScope(root),/Merge commits/); // No caller-controlled anchor in the public gate.
  write('resources/js/main.jsx','// Later bounded feature fix\n');stage();git(root,'commit','-qm','Feature followup');
  assert.deepEqual(seriesHistory(root,base,fixturePolicy(root,feature,base)),[git(root,'rev-parse','HEAD'),feature]);
}));
test('tooling integration rejects wrong first parent, reversed parents and unpublished second parent',()=>{
  for(const kind of ['first','reversed','second','octopus'])integrationFixture(({root,feature,base,oldBase,canonical,commitMerge})=>{
    const tree=canonical(),extra=git(root,'commit-tree',tree,'-p',feature,'-m','Unauthorized intermediate');
    const parents=kind==='first'?[extra,base]:kind==='reversed'?[base,feature]:kind==='second'?[feature,oldBase]:[feature,base,extra];
    commitMerge(tree,parents);assert.throws(()=>seriesHistory(root,base,fixturePolicy(root,feature,base)),/exact approved feature head/);
  });
});
test('canonical integration rejects injected files and manual conflict resolutions',()=>{
  integrationFixture(({root,feature,base,write,stage,commitMerge})=>{write('app/Foreign.php','<?php // injection\n');stage();commitMerge(git(root,'write-tree'));assert.throws(()=>seriesHistory(root,base,fixturePolicy(root,feature,base)),/canonical merge tree/);});
  integrationFixture(({root,feature,write,stage,commitMerge})=>{
    git(root,'checkout','codex/chatdev-integration');write('resources/js/main.jsx','// Conflicting parent\n');stage();git(root,'commit','-qm','Conflict');git(root,'push','origin','codex/chatdev-integration');const base=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
    commitMerge(git(root,'write-tree'),[feature,base]);assert.throws(()=>seriesHistory(root,base,fixturePolicy(root,feature,base)),/conflict-free/);
  });
});
test('unapproved second merges and moved published tooling bases invalidate integration',()=>{
  integrationFixture(({root,feature,base,commitMerge})=>{const merge=commitMerge(),tree=git(root,'write-tree'),extra=git(root,'commit-tree',tree,'-p',feature,'-m','Other branch');const second=git(root,'commit-tree',tree,'-p',merge,'-p',extra,'-m','Second merge');git(root,'reset','--hard',second);assert.throws(()=>seriesHistory(root,base,fixturePolicy(root,feature,base)),/advanced published parent/);});
  integrationFixture(({root,feature,base,commitMerge,write,stage})=>{commitMerge();git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/later.mjs','// Later parent\n');stage();git(root,'commit','-qm','Moved parent');const moved=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');assert.throws(()=>seriesHistory(root,moved,fixturePolicy(root,feature,base)),/published tooling parent/);});
});
test('pre-integration feature changes and reverted forbidden paths remain historically auditable',()=>integrationFixture(({root,feature,base,write,stage,canonical})=>{
  write('app/Foreign.php','<?php // forbidden history\n');stage();git(root,'commit','-qm','Foreign feature edit');const foreign=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const first=git(root,'rev-parse','HEAD');
  const tree=git(root,'merge-tree','--write-tree',first,base).split('\n')[0],merge=git(root,'commit-tree',tree,'-p',first,'-p',base,'-m','Canonical integration');git(root,'reset','--hard',merge);
  // Even for an explicitly anchored synthetic checkpoint, foreign/revert
  // commits are returned to the existing historical path and plan validators.
  const history=seriesHistory(root,base,fixturePolicy(root,first,base));assert(history.includes(foreign));assert(history.includes(feature));
  assert(git(root,'diff-tree','--no-commit-id','--name-only','-r',foreign).includes('app/Foreign.php'));
  assert.throws(()=>fixtureIntegrationScope(root,first),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
test('approved stacked step8 product paths are scoped, while main is not',()=>fixture(({root,write})=>{write('app/Support/SeriesPlaylists.php','<?php // fixture\n');assert.equal(seriesScope(root).parent,'codex/chatdev-integration');git(root,'checkout','-b','main');assert.throws(()=>seriesScope(root),/dedicated/);}));
test('hook, progress, original gates and unknown files remain forbidden',()=>{for(const file of ['.codex/hooks.json','.cursor/hooks.json','harness/progress.json','AGENTS.md','tools/harness/workflow.mjs','tools/harness/maintenance.mjs','app/Unrelated.php'])fixture(({root,write})=>{if(file==='harness/progress.json'){fs.appendFileSync(path.join(root,file),'\n');}else write(file,'Changed fixture\n');assert.throws(()=>seriesScope(root));});});
test('existing plan and checkpoint checks cannot be weakened or reordered',()=>{for(const mutation of ['argv','order','branch'])fixture(({root,write,plan})=>{if(mutation==='argv')plan.checks['harness-tests'].argv=[process.execPath,'-e','process.exit(0)'];if(mutation==='order')plan.steps[7].checkpointChecks.reverse();if(mutation==='branch')plan.branch='codex/series-seasons';write('harness/plan.json',JSON.stringify(plan));assert.throws(()=>seriesScope(root),/plan|branch/);});});
test('only exact bounded real step8 script definition is an allowed addition',()=>fixture(({root,write,plan})=>{plan.checks['step-08-series']={argv:['bash','tools/test/step-08-series.sh'],timeoutMs:30000};write('harness/plan.json',JSON.stringify(plan));assert.throws(()=>seriesScope(root),/script is missing/);write('tools/test/step-08-series.sh','#!/bin/sh\nprintf "Synthetic isolated series check\\n"\n');assert.deepEqual(seriesScope(root).checks,['harness-tests','staged-safety','step-08-series']);plan.checks['step-08-series'].argv=['bash','-c','true'];write('harness/plan.json',JSON.stringify(plan));assert.throws(()=>seriesScope(root),/approved real/);}));
test('scope audits intermediate commits, not only restored final tree',()=>fixture(({root,write,stage})=>{write('app/Unrelated.php','<?php // fixture\n');stage();git(root,'commit','-qm','Forbidden fixture');git(root,'revert','--no-edit','HEAD');assert.throws(()=>seriesScope(root),/Outside approved series/);}));
test('temporary plan weakening cannot be hidden by a later restoration',()=>fixture(({root,write,stage,plan})=>{const original=JSON.stringify(plan);plan.steps[7].checkpointChecks=['staged-safety'];write('harness/plan.json',JSON.stringify(plan));stage();git(root,'commit','-qm','Forbidden weaker plan');write('harness/plan.json',original);stage();git(root,'commit','-qm','Restore plan');assert.throws(()=>seriesScope(root),/existing plan/);}));
test('symlinks, deletion and unreferenced generated assets are rejected',()=>{
  fixture(({root})=>{fs.symlinkSync('main.jsx',path.join(root,'resources/js/playlistSeries.js'));assert.throws(()=>seriesScope(root),/regular files/);});
  fixture(({root})=>{fs.unlinkSync(path.join(root,'resources/js/main.jsx'));assert.throws(()=>seriesScope(root),/regular files/);});
  fixture(({root,write})=>{write('build/assets/unreferenced.js','// fixture\n');git(root,'add','-f','build/assets/unreferenced.js');assert.throws(()=>seriesScope(root),/Outside approved series/);});
});
test('verification executes every preserved checkpoint argv and configured series check',()=>fixture(({root,write,stage,plan})=>{write('resources/js/main.jsx','// Approved synthetic change\n');plan.checks['step-08-series']={argv:['bash','tools/test/step-08-series.sh'],timeoutMs:5000};write('harness/plan.json',JSON.stringify(plan));write('tools/test/step-08-series.sh','#!/bin/sh\nprintf "Synthetic isolated series check\\n"\n');stage();verifySeries(root);const receipt=JSON.parse(fs.readFileSync(localPath(root,'series-verification.json')));assert.deepEqual(receipt.checks.map(c=>c.id),['harness-tests','staged-safety','step-08-series']);assert.equal(receipt.purpose,'series-checkpoint-only');assert.equal(receipt.step,8);}));
test('failed checks remove verification eligibility',()=>fixture(({root,write,stage})=>{write('resources/js/main.jsx','// fixture\n');stage();const old=process.env.SERIES_FIXTURE_FAIL;process.env.SERIES_FIXTURE_FAIL='1';try{assert.throws(()=>verifySeries(root),/check failed/);}finally{if(old===undefined)delete process.env.SERIES_FIXTURE_FAIL;else process.env.SERIES_FIXTURE_FAIL=old;}assert(!fs.existsSync(localPath(root,'series-verification.json')));}));
test('stale source or index proof cannot be published',()=>fixture(({root,write,stage})=>{write('resources/js/main.jsx','// first fixture\n');stage();verifySeries(root);write('resources/js/main.jsx','// changed fixture\n');stage();assert.throws(()=>publishSeries(root,'Synthetic checkpoint'),/stale/);}));
test('fresh remote parent movement blocks commit despite stale tracking ref',()=>fixture(({root,write,stage,remote})=>{write('resources/js/main.jsx','// fixture\n');stage();verifySeries(root);const before=git(root,'rev-parse','HEAD'),tree=git(root,'rev-parse','HEAD^{tree}');const advanced=git(root,'commit-tree',tree,'-p',before,'-m','Synthetic published parent advance');git(root,'push','origin',advanced+':refs/heads/codex/chatdev-integration');git(root,'update-ref','refs/remotes/origin/codex/chatdev-integration',before);assert.throws(()=>publishSeries(root,'Do not commit'),/parent moved/);assert.equal(git(root,'rev-parse','HEAD'),before);}));
test('successful checkpoint confirms only feature branch and preserves progress',()=>fixture(({root,write,stage,remote})=>{const progress=fs.readFileSync(path.join(root,'harness/progress.json'),'utf8');write('resources/js/main.jsx','// Synthetic series change\n');stage();verifySeries(root);publishSeries(root,'Synthetic bounded series checkpoint');const head=git(root,'rev-parse','HEAD');assert.equal(git(remote,'rev-parse','refs/heads/codex/series-seasons'),head);assert.equal(fs.readFileSync(path.join(root,'harness/progress.json'),'utf8'),progress);assert.equal(JSON.parse(fs.readFileSync(localPath(root,'series-publication.json'))).purpose,'series-checkpoint-only');assert.throws(()=>git(remote,'rev-parse','refs/heads/main'));}));
test('push failure stays blocked and never fabricates publication proof',()=>fixture(({root,write,stage,remote})=>{write('resources/js/main.jsx','// fixture\n');stage();verifySeries(root);fs.writeFileSync(path.join(remote,'hooks/pre-receive'),'#!/bin/sh\nexit 1\n',{mode:0o755});assert.throws(()=>publishSeries(root,'Synthetic rejected push'),/push or confirmation failed/);assert(!fs.existsSync(localPath(root,'series-publication.json')));assert.equal(JSON.parse(fs.readFileSync(localPath(root,'state.json'))).mode,'blocked');}));
test('historical referenced chunks stay permitted across successive generated manifests',()=>fixture(({root,write,stage})=>{
  write('build/assets/first.js','// Synthetic first release\n');write('build/manifest.json',JSON.stringify({entry:{file:'assets/first.js'}}));git(root,'add','-f','build/assets/first.js');stage();git(root,'commit','-qm','Synthetic first release');
  write('build/assets/second.js','// Synthetic next release\n');write('build/manifest.json',JSON.stringify({entry:{file:'assets/second.js'}}));git(root,'add','-f','build/assets/second.js');stage();assert.equal(seriesScope(root).branch,'codex/series-seasons');
  write('build/assets/orphan.js','// Not referenced by any release\n');git(root,'add','-f','build/assets/orphan.js');assert.throws(()=>seriesScope(root),/Outside approved series/);
}));
test('step8 gate cannot run after a premature progress transition or merge commit',()=>{
  fixture(({root,write})=>{write('harness/progress.json',JSON.stringify({schemaVersion:1,activeStep:7,steps:Array.from({length:8},(_,i)=>({id:i+1,status:i<6?'accepted':i===6?'active':'pending'}))}));assert.throws(()=>seriesScope(root),/active step 8/);});
  fixture(({root})=>{const original=git(root,'rev-parse','HEAD'),tree=git(root,'write-tree');const first=git(root,'commit-tree',tree,'-p',original,'-m','Synthetic parent');const merge=git(root,'commit-tree',tree,'-p',first,'-p',original,'-m','Synthetic merge');git(root,'reset','--hard',merge);assert.throws(()=>seriesScope(root),/Merge commits/);});
});
test('a failed retry cannot retain an older passing verification receipt',()=>fixture(({root,write,stage})=>{
  write('resources/js/main.jsx','// fixture\n');stage();verifySeries(root);assert(fs.existsSync(localPath(root,'series-verification.json')));
  const old=process.env.SERIES_FIXTURE_FAIL;process.env.SERIES_FIXTURE_FAIL='1';try{assert.throws(()=>verifySeries(root),/check failed/);}finally{if(old===undefined)delete process.env.SERIES_FIXTURE_FAIL;else process.env.SERIES_FIXTURE_FAIL=old;}
  assert(!fs.existsSync(localPath(root,'series-verification.json')));
}));
test('failed push can retry the same checked snapshot after explicit resume',()=>fixture(({root,write,stage,remote})=>{
  write('resources/js/main.jsx','// fixture\n');stage();verifySeries(root);const hook=path.join(remote,'hooks/pre-receive');fs.writeFileSync(hook,'#!/bin/sh\nexit 1\n',{mode:0o755});assert.throws(()=>publishSeries(root,'Synthetic rejected push'));
  const committed=git(root,'rev-parse','HEAD');fs.unlinkSync(hook);setMode(root,'implementing');publishSeries(root,'Retry unchanged checkpoint');assert.equal(git(root,'rev-parse','HEAD'),committed);assert.equal(git(remote,'rev-parse','refs/heads/codex/series-seasons'),committed);
}));
test('ignored release bytes must be indexed and are bound to checkpoint proof',()=>fixture(({root,write,stage})=>{
  write('build/assets/release.js','// Synthetic first bytes\n');write('build/manifest.json',JSON.stringify({entry:{file:'assets/release.js'}}));stage();
  assert.throws(()=>seriesScope(root),/tracked\/staged in the index/);
  git(root,'add','-f','build/assets/release.js');assert.equal(seriesScope(root).branch,'codex/series-seasons');verifySeries(root);
  write('build/assets/release.js','// Changed release bytes\n');git(root,'add','-f','build/assets/release.js');assert.throws(()=>publishSeries(root,'Do not publish changed bytes'),/stale/);
}));
test('changing a push URL invalidates proof before any commit or external push',()=>fixture(({home,root,write,stage})=>{
  write('resources/js/main.jsx','// Synthetic change\n');stage();verifySeries(root);const before=git(root,'rev-parse','HEAD'), other=path.join(home,'different-push.git');git(home,'init','--bare',other);
  git(root,'remote','set-url','--push','origin',other);assert.throws(()=>publishSeries(root,'Do not commit to changed destination'),/stale/);assert.equal(git(root,'rev-parse','HEAD'),before);assert.throws(()=>git(other,'rev-parse','refs/heads/codex/series-seasons'));
}));
test('adding a secondary push destination also invalidates checked publication',()=>fixture(({home,root,write,stage,remote})=>{
  git(root,'remote','set-url','--add','--push','origin',remote);write('resources/js/main.jsx','// Synthetic change\n');stage();verifySeries(root);const before=git(root,'rev-parse','HEAD'), other=path.join(home,'secondary-push.git');git(home,'init','--bare',other);
  git(root,'remote','set-url','--add','--push','origin',other);assert.throws(()=>publishSeries(root,'Do not push to any destination'),/stale/);assert.equal(git(root,'rev-parse','HEAD'),before);for(const destination of [remote,other])assert.throws(()=>git(destination,'rev-parse','refs/heads/codex/series-seasons'));
}));
test('existing canonical English catalog is allowed but new or foreign catalogs are not',()=>{
  fixture(({root,write})=>{write('lang/en/english.php',"<?php return ['Existing'=>'Existing','Series'=>'Series'];\n");assert.equal(seriesScope(root).branch,'codex/series-seasons');});
  for(const file of ['lang/new/new.php','lang/en/foreign.php','lang/en/untracked.php'])fixture(({root,write})=>{write(file,'<?php // Unapproved catalog fixture\n');assert.throws(()=>seriesScope(root),/Outside approved series/);});
});
test('CI preserves unconditional tooling contracts and gates every series setup step by exact feature branch',()=>{
  const workflow=fs.readFileSync(new URL('../../.github/workflows/chatdev.yml',import.meta.url),'utf8');
  assert.match(workflow,/permissions:\n  contents: read\njobs:\n  contracts:/);
  assert.match(workflow,/persist-credentials: false/);
  assert(!/pull_request_target|continue-on-error|--ignore-platform-req|contents: write|secrets\./.test(workflow));
  const steps=workflow.split(/(?=^      - )/m).slice(1);
  const tooling=steps.find(s=>s.includes('node --test tools/test/harness.test.mjs'));
  assert(tooling&&!tooling.includes('if:'));
  const feature=steps.filter(s=>s.includes('name: Series '));assert.equal(feature.length,8);
  for(const step of feature)assert.match(step,/^        if: github\.head_ref == 'codex\/series-seasons'$/m);
  const actions=[...workflow.matchAll(/uses: ([^\s@]+)@([^\s]+)/g)];assert.equal(actions.length,3);for(const action of actions)assert.match(action[2],/^[a-f0-9]{40}$/);
  assert.match(workflow,/shivammathur\/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962/);
  assert.match(workflow,/php-version: '8\.5'/);assert.match(workflow,/extensions: iconv, pdo_sqlite/);
});
test('CI series job installs locked dependencies and executes the real composed runner',()=>{
  const workflow=fs.readFileSync(new URL('../../.github/workflows/chatdev.yml',import.meta.url),'utf8');
  for(const command of ['composer install --no-scripts --no-interaction --prefer-dist --no-progress','npm ci --ignore-scripts','npm ci --prefix tools/test/ui --ignore-scripts','node tools/test/ui/node_modules/playwright/cli.js install --with-deps chromium','sudo apt-get update && sudo apt-get install --yes --no-install-recommends ffmpeg','npm run build','bash tools/test/step-08-series.sh'])assert(workflow.includes('run: '+command));
  for(const file of ['harness/plan.json','app/Support/SeriesPlaylists.php','app/Http/Controllers/Api/SeriesPlaylistController.php','routes/api.php','routes/api_v1.php','resources/js/main.jsx','resources/js/components/PlaylistSeries.jsx','resources/js/series-context.js','resources/js/styles/playlist-series.css','lang/*/*.php','build/manifest.json','tools/test/step-08-series.sh','tools/test/php/series-playlist-checks.php','tools/test/series-seasons.test.mjs','tools/test/ui/series-seasons.mjs'])assert(workflow.includes("- '"+file+"'"),'Missing series trigger path '+file);
  assert(workflow.indexOf('run: npm run build')<workflow.indexOf('run: bash tools/test/step-08-series.sh'));
});

function twoIntegrationFixture(fn){return integrationFixture(ctx=>{
  const {root,feature,base,commitMerge,write,stage}=ctx,firstMerge=commitMerge();
  write('resources/js/main.jsx','// Feature after first integration\n');stage();git(root,'commit','-qm','Approved next feature checkpoint');
  const nextHead=git(root,'rev-parse','HEAD');git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/second-parent.mjs','// New approved tooling\n');stage();git(root,'commit','-qm','New published tooling parent');git(root,'push','origin','codex/chatdev-integration');
  const newBase=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
  const policy=fixturePolicy(root,feature,base,firstMerge,nextHead);
  const secondMerge=(parents=[nextHead,newBase],tree=null)=>{tree ||= git(root,'merge-tree','--write-tree',nextHead,newBase).split('\n')[0];const merge=git(root,'commit-tree',tree,...parents.flatMap(p=>['-p',p]),'-m','Second canonical integration');git(root,'reset','--hard',merge);return merge;};
  return fn({...ctx,firstMerge,nextHead,newBase,policy,secondMerge});
});}
test('exactly two approved canonical integrations retain all feature history and exclude tooling ancestry',()=>twoIntegrationFixture(({root,feature,base,firstMerge,nextHead,newBase,policy,secondMerge})=>{
  const second=secondMerge();assert.deepEqual(seriesHistory(root,newBase,policy),[nextHead,feature]);
  assert.equal(fixtureIntegrationScope(root,feature,policy).base,newBase);
  const commits=seriesHistory(root,newBase,policy);for(const excluded of [base,newBase,firstMerge,second])assert(!commits.includes(excluded));
}));
test('second integration rejects wrong head, reversed parents, stale parent and octopus',()=>{
  for(const kind of ['head','reverse','stale','octopus'])twoIntegrationFixture(({root,feature,base,nextHead,newBase,policy,secondMerge})=>{
    const parents=kind==='head'?[feature,newBase]:kind==='reverse'?[newBase,nextHead]:kind==='stale'?[nextHead,base]:[nextHead,newBase,feature];secondMerge(parents);
    assert.throws(()=>seriesHistory(root,newBase,policy),/exact approved feature head|explicitly approved/);
  });
});
test('second integration rejects injected tree, forged first merge identity and any third merge',()=>{
  twoIntegrationFixture(({root,write,stage,newBase,policy,secondMerge})=>{write('app/Foreign.php','<?php // injected\n');stage();secondMerge(undefined,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,newBase,policy),/canonical merge tree/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{secondMerge();assert.throws(()=>seriesHistory(root,newBase,{...policy,firstMerge:policy.firstHead}),/explicitly approved/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{const second=secondMerge(),tree=git(root,'write-tree'),side=git(root,'commit-tree',tree,'-p',second,'-m','Side feature'),third=git(root,'commit-tree',tree,'-p',second,'-p',side,'-m','Unauthorized third');git(root,'reset','--hard',third);assert.throws(()=>seriesHistory(root,newBase,policy),/explicitly approved/);});
});
test('two integrations require preserved first merge and tooling-parent ancestry',()=>{
  twoIntegrationFixture(({root,feature,newBase,policy,secondMerge})=>{secondMerge();assert.throws(()=>seriesHistory(root,newBase,{...policy,nextHead:feature}),/retain their exact ancestry/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{secondMerge();const other=git(root,'commit-tree',git(root,'write-tree'),'-m','Unrelated parent');assert.throws(()=>seriesHistory(root,other,policy),/retain their exact ancestry/);});
});
test('foreign edits reverted between integrations remain forbidden by full historical scope',()=>twoIntegrationFixture(({root,feature,write,stage,newBase,policy,secondMerge})=>{
  write('app/Foreign.php','<?php // forbidden intermediate\n');stage();git(root,'commit','-qm','Forbidden intermediate');const forbidden=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const nextHead=git(root,'rev-parse','HEAD'),updated={...policy,nextHead};secondMerge([nextHead,newBase],git(root,'merge-tree','--write-tree',nextHead,newBase).split('\n')[0]);
  assert(seriesHistory(root,newBase,updated).includes(forbidden));assert.throws(()=>fixtureIntegrationScope(root,feature,updated),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
function threeIntegrationFixture(fn){return twoIntegrationFixture(ctx=>{
  const {root,write,stage,newBase,policy,secondMerge}=ctx,second=secondMerge();
  write('resources/js/main.jsx','// Approved feature checkpoint before third integration\n');stage();git(root,'commit','-qm','Approved third feature anchor');
  const thirdHead=git(root,'rev-parse','HEAD');git(root,'push','origin','HEAD:refs/heads/codex/series-seasons');
  git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/third-parent.mjs','// Latest approved published tooling\n');stage();git(root,'commit','-qm','Third published tooling parent');git(root,'push','origin','codex/chatdev-integration');
  const thirdBase=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
  const threePolicy={...policy,secondMerge:second,secondParent:newBase,thirdHead};
  const thirdMerge=(parents=[thirdHead,thirdBase],tree=null)=>{
    tree ||= git(root,'merge-tree','--write-tree',parents[0],parents[1]).split('\n')[0];
    const merge=git(root,'commit-tree',tree,...parents.flatMap(p=>['-p',p]),'-m','Third canonical integration');git(root,'reset','--hard',merge);return merge;
  };
  return fn({...ctx,second,thirdHead,thirdBase,threePolicy,thirdMerge});
});}
test('three canonical integrations preserve both old identities and all feature history',()=>threeIntegrationFixture(({root,feature,nextHead,thirdHead,thirdBase,threePolicy,thirdMerge,write,stage})=>{
  thirdMerge();assert.deepEqual(seriesHistory(root,thirdBase,threePolicy),[thirdHead,nextHead,feature]);
  assert.equal(fixtureIntegrationScope(root,feature,threePolicy).base,thirdBase);
  write('resources/js/main.jsx','// Bounded feature followup after integration\n');stage();git(root,'commit','-qm','Feature followup');
  assert.equal(seriesHistory(root,thirdBase,threePolicy)[0],git(root,'rev-parse','HEAD'));
}));
test('third integration rejects wrong feature head, reversed parents, stale parent and octopus',()=>{
  for(const kind of ['head','reverse','stale','octopus'])threeIntegrationFixture(({root,nextHead,newBase,thirdHead,thirdBase,threePolicy,thirdMerge})=>{
    const parents=kind==='head'?[nextHead,thirdBase]:kind==='reverse'?[thirdBase,thirdHead]:kind==='stale'?[thirdHead,newBase]:[thirdHead,thirdBase,nextHead];
    thirdMerge(parents,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,thirdBase,threePolicy),/exact approved feature head|exact approved second|explicitly approved/);
  });
});
test('third integration rejects injected trees, manual conflict resolution and forged historical second merge',()=>{
  threeIntegrationFixture(({root,write,stage,thirdBase,threePolicy,thirdMerge})=>{write('app/Foreign.php','<?php // forged tree\n');stage();thirdMerge(undefined,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,thirdBase,threePolicy),/canonical merge tree/);});
  threeIntegrationFixture(({root,write,stage,thirdHead,threePolicy,thirdMerge})=>{
    git(root,'checkout','codex/chatdev-integration');write('resources/js/main.jsx','// Conflicting parent change\n');stage();git(root,'commit','-qm','Conflicting parent');git(root,'push','origin','codex/chatdev-integration');const base=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
    thirdMerge([thirdHead,base],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,base,threePolicy),/conflict-free/);
  });
  threeIntegrationFixture(({root,thirdHead,thirdBase,threePolicy,thirdMerge})=>{thirdMerge();assert.throws(()=>seriesHistory(root,thirdBase,{...threePolicy,secondMerge:thirdHead}),/exact approved second/);});
});
test('third integration requires preserved second ancestry, new published base and no fourth merge',()=>{
  threeIntegrationFixture(({root,feature,thirdBase,threePolicy,thirdMerge})=>{thirdMerge();assert.throws(()=>seriesHistory(root,thirdBase,{...threePolicy,thirdHead:feature}),/second merge.*ancestry/);});
  threeIntegrationFixture(({root,newBase,thirdHead,threePolicy,thirdMerge})=>{thirdMerge([thirdHead,newBase],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,newBase,threePolicy),/advanced published parent/);});
  threeIntegrationFixture(({root,thirdBase,threePolicy,thirdMerge})=>{const third=thirdMerge(),tree=git(root,'write-tree'),side=git(root,'commit-tree',tree,'-p',third,'-m','Unapproved side branch'),fourth=git(root,'commit-tree',tree,'-p',third,'-p',side,'-m','Forbidden fourth merge');git(root,'reset','--hard',fourth);assert.throws(()=>seriesHistory(root,thirdBase,threePolicy),/explicitly approved/);});
});
test('foreign commits reverted before the third integration still fail the full public path audit',()=>threeIntegrationFixture(({root,feature,write,stage,thirdBase,threePolicy,thirdMerge})=>{
  write('app/Foreign.php','<?php // forbidden intermediate\n');stage();git(root,'commit','-qm','Foreign intermediate before third merge');const foreign=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const thirdHead=git(root,'rev-parse','HEAD'),policy={...threePolicy,thirdHead};thirdMerge([thirdHead,thirdBase]);
  assert(seriesHistory(root,thirdBase,policy).includes(foreign));assert.throws(()=>fixtureIntegrationScope(root,feature,policy),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
test('public third-integration policy pins both historical merges and the exact separately approved checkpoint',()=>{
  const source=fs.readFileSync(new URL('../harness/series-maintenance.mjs',import.meta.url),'utf8');
  for(const [name,value] of Object.entries({approvedFirstIntegration:'c17e930f33a2564c4b4deb92eba5b5792d34f64a',approvedFirstToolingParent:'c8d403968666b789fa189e7c704a0284a3b229a5',approvedSecondIntegration:'3a07a5aaa3978b677649ae00df5235eabd79b20f',approvedSecondToolingParent:'be06d13fcddbfc090c46ae201ddbb2ce1033ba6c',approvedThirdIntegrationHead:'65c04875241caede38045440446af44054059df6'}))assert(source.includes(`const ${name}='${value}';`));
});
function fourIntegrationFixture(fn){return threeIntegrationFixture(ctx=>{
  const {root,write,stage,thirdBase,threePolicy,thirdMerge}=ctx,third=thirdMerge();
  write('resources/js/main.jsx','// Final approved language checkpoint before receipt repair\n');stage();git(root,'commit','-qm','Fourth approved feature anchor');
  const fourthHead=git(root,'rev-parse','HEAD');git(root,'push','origin','HEAD:refs/heads/codex/series-seasons');
  git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/fourth-parent.mjs','// Reviewed receipt repair tooling\n');stage();git(root,'commit','-qm','Fourth published parent');git(root,'push','origin','codex/chatdev-integration');
  const fourthBase=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
  const fourPolicy={...threePolicy,thirdMerge:third,thirdParent:thirdBase,fourthHead};
  const fourthMerge=(parents=[fourthHead,fourthBase],tree=null)=>{
    tree ||= git(root,'merge-tree','--write-tree',parents[0],parents[1]).split('\n')[0];
    const merge=git(root,'commit-tree',tree,...parents.flatMap(p=>['-p',p]),'-m','Fourth canonical integration');git(root,'reset','--hard',merge);return merge;
  };
  return fn({...ctx,third,fourthHead,fourthBase,fourPolicy,fourthMerge});
});}
test('four exact canonical integrations preserve all three historical anchors and feature commits',()=>fourIntegrationFixture(({root,feature,nextHead,thirdHead,fourthHead,fourthBase,fourPolicy,fourthMerge})=>{
  fourthMerge();assert.deepEqual(seriesHistory(root,fourthBase,fourPolicy),[fourthHead,thirdHead,nextHead,feature]);
  assert.equal(fixtureIntegrationScope(root,feature,fourPolicy).base,fourthBase);
}));
test('fourth integration rejects wrong head, reversed parents, stale parent and octopus',()=>{
  for(const kind of ['head','reverse','stale','octopus'])fourIntegrationFixture(({root,thirdHead,thirdBase,fourthHead,fourthBase,fourPolicy,fourthMerge})=>{
    const parents=kind==='head'?[thirdHead,fourthBase]:kind==='reverse'?[fourthBase,fourthHead]:kind==='stale'?[fourthHead,thirdBase]:[fourthHead,fourthBase,thirdHead];
    fourthMerge(parents,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,fourthBase,fourPolicy),/exact approved feature head|explicitly approved|exact approved third/);
  });
});
test('fourth integration rejects injected trees and manual conflict resolutions',()=>{
  fourIntegrationFixture(({root,write,stage,fourthBase,fourPolicy,fourthMerge})=>{write('app/Foreign.php','<?php // injected\n');stage();fourthMerge(undefined,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,fourthBase,fourPolicy),/canonical merge tree/);});
  fourIntegrationFixture(({root,write,stage,fourthHead,fourPolicy,fourthMerge})=>{
    git(root,'checkout','codex/chatdev-integration');write('resources/js/main.jsx','// Conflicting parent\n');stage();git(root,'commit','-qm','Conflict');git(root,'push','origin','codex/chatdev-integration');const base=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
    fourthMerge([fourthHead,base],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,base,fourPolicy),/conflict-free/);
  });
});
test('fourth integration requires exact old identities, ancestor retention, advanced base and no fifth merge',()=>{
  for(const field of ['firstMerge','secondMerge','thirdMerge'])fourIntegrationFixture(({root,fourthHead,fourthBase,fourPolicy,fourthMerge})=>{fourthMerge();assert.throws(()=>seriesHistory(root,fourthBase,{...fourPolicy,[field]:fourthHead}),/explicitly approved|exact approved/);});
  fourIntegrationFixture(({root,feature,fourthBase,fourPolicy,fourthMerge})=>{fourthMerge();assert.throws(()=>seriesHistory(root,fourthBase,{...fourPolicy,fourthHead:feature}),/third merge.*ancestry/);});
  fourIntegrationFixture(({root,thirdBase,fourthHead,fourPolicy,fourthMerge})=>{fourthMerge([fourthHead,thirdBase],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,thirdBase,fourPolicy),/advanced published parent/);});
  fourIntegrationFixture(({root,fourthBase,fourPolicy,fourthMerge})=>{const fourth=fourthMerge(),tree=git(root,'write-tree'),side=git(root,'commit-tree',tree,'-p',fourth,'-m','Unapproved side'),fifth=git(root,'commit-tree',tree,'-p',fourth,'-p',side,'-m','Forbidden fifth');git(root,'reset','--hard',fifth);assert.throws(()=>seriesHistory(root,fourthBase,fourPolicy),/explicitly approved/);});
});
test('foreign commits restored before fourth integration remain forbidden historically',()=>fourIntegrationFixture(({root,feature,write,stage,fourthBase,fourPolicy,fourthMerge})=>{
  write('app/Foreign.php','<?php // forbidden intermediate\n');stage();git(root,'commit','-qm','Foreign before fourth');const foreign=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const fourthHead=git(root,'rev-parse','HEAD'),policy={...fourPolicy,fourthHead};fourthMerge([fourthHead,fourthBase]);
  assert(seriesHistory(root,fourthBase,policy).includes(foreign));assert.throws(()=>fixtureIntegrationScope(root,feature,policy),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
test('an unbound fourth checkpoint cannot authorize an integration',()=>fourIntegrationFixture(({root,fourthBase,fourPolicy,fourthMerge})=>{fourthMerge();assert.throws(()=>seriesHistory(root,fourthBase,{...fourPolicy,fourthHead:'PENDING_CONFIRMED_LANGUAGE_CHECKPOINT'}),/awaits the confirmed/);}));
test('public fourth policy pins the prior integration, parent and confirmed feature checkpoint',()=>{
  const source=fs.readFileSync(new URL('../harness/series-maintenance.mjs',import.meta.url),'utf8');
  for(const [name,value] of Object.entries({approvedThirdIntegration:'88e731395587dfba5fa8dbb336fc56fa7008eb2e',approvedThirdToolingParent:'ac406456662c78a154b445328e3539ab87468193',approvedFourthIntegrationHead:'dfa0dc1dc43c5774e711846bac7b558f5b530cc8'}))assert(source.includes(`const ${name}='${value}';`));
});
function fiveIntegrationFixture(fn){return fourIntegrationFixture(ctx=>{
  const {root,write,stage,fourthBase,fourPolicy,fourthMerge}=ctx,fourth=fourthMerge();
  const fifthHead=fourth;git(root,'push','origin','HEAD:refs/heads/codex/series-seasons');
  git(root,'checkout','codex/chatdev-integration');write('tools/chatdev/fifth-parent.mjs','// Exact index guard follow-up\n');stage();git(root,'commit','-qm','Fifth published tooling parent');git(root,'push','origin','codex/chatdev-integration');
  const fifthBase=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
  const fivePolicy={...fourPolicy,fourthMerge:fourth,fourthParent:fourthBase,fifthHead};
  const fifthMerge=(parents=[fifthHead,fifthBase],tree=null)=>{
    tree ||= git(root,'merge-tree','--write-tree',parents[0],parents[1]).split('\n')[0];
    const merge=git(root,'commit-tree',tree,...parents.flatMap(p=>['-p',p]),'-m','Fifth canonical integration');git(root,'reset','--hard',merge);return merge;
  };
  return fn({...ctx,fourth,fifthHead,fifthBase,fivePolicy,fifthMerge});
});}
test('fifth canonical integration retains four exact old merges and all feature history',()=>fiveIntegrationFixture(({root,feature,nextHead,thirdHead,fourthHead,fifthBase,fivePolicy,fifthMerge})=>{
  fifthMerge();assert.deepEqual(seriesHistory(root,fifthBase,fivePolicy),[fourthHead,thirdHead,nextHead,feature]);
  assert.equal(fixtureIntegrationScope(root,feature,fivePolicy).base,fifthBase);
}));
test('fifth integration rejects wrong feature head, reversed parents, stale parent and octopus',()=>{
  for(const kind of ['head','reverse','stale','octopus'])fiveIntegrationFixture(({root,fourthHead,fourthBase,fifthHead,fifthBase,fivePolicy,fifthMerge})=>{
    const parents=kind==='head'?[fourthHead,fifthBase]:kind==='reverse'?[fifthBase,fifthHead]:kind==='stale'?[fifthHead,fourthBase]:[fifthHead,fifthBase,fourthHead];
    fifthMerge(parents,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,fifthBase,fivePolicy),/exact approved feature head|explicitly approved|exact approved fourth/);
  });
});
test('fifth integration rejects injected tree and manual conflict resolution',()=>{
  fiveIntegrationFixture(({root,write,stage,fifthBase,fivePolicy,fifthMerge})=>{write('app/Foreign.php','<?php // injected\n');stage();fifthMerge(undefined,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,fifthBase,fivePolicy),/canonical merge tree/);});
  fiveIntegrationFixture(({root,write,stage,fifthHead,fivePolicy,fifthMerge})=>{
    git(root,'checkout','codex/chatdev-integration');write('resources/js/main.jsx','// Conflicting fifth parent\n');stage();git(root,'commit','-qm','Conflict');git(root,'push','origin','codex/chatdev-integration');const base=git(root,'rev-parse','HEAD');git(root,'checkout','codex/series-seasons');
    fifthMerge([fifthHead,base],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,base,fivePolicy),/conflict-free/);
  });
});
test('fifth integration rejects missing historical identities, unrelated checkpoint, unchanged parent and sixth merge',()=>{
  for(const field of ['firstMerge','secondMerge','thirdMerge','fourthMerge'])fiveIntegrationFixture(({root,fifthHead,fifthBase,fivePolicy,fifthMerge})=>{fifthMerge();assert.throws(()=>seriesHistory(root,fifthBase,{...fivePolicy,[field]:fifthHead===fivePolicy[field]?fivePolicy.firstHead:fifthHead}),/explicitly approved|exact approved/);});
  fiveIntegrationFixture(({root,feature,fifthBase,fivePolicy,fifthMerge})=>{fifthMerge();assert.throws(()=>seriesHistory(root,fifthBase,{...fivePolicy,fifthHead:feature}),/fourth merge.*ancestry/);});
  fiveIntegrationFixture(({root,fourthBase,fifthHead,fivePolicy,fifthMerge})=>{fifthMerge([fifthHead,fourthBase],git(root,'write-tree'));assert.throws(()=>seriesHistory(root,fourthBase,fivePolicy),/advanced published parent/);});
  fiveIntegrationFixture(({root,fifthBase,fivePolicy,fifthMerge})=>{const fifth=fifthMerge(),tree=git(root,'write-tree'),side=git(root,'commit-tree',tree,'-p',fifth,'-m','Unapproved side'),sixth=git(root,'commit-tree',tree,'-p',fifth,'-p',side,'-m','Forbidden sixth');git(root,'reset','--hard',sixth);assert.throws(()=>seriesHistory(root,fifthBase,fivePolicy),/explicitly approved/);});
});
test('restored foreign commits before fifth integration still fail the full historical path audit',()=>fiveIntegrationFixture(({root,feature,write,stage,fifthBase,fivePolicy,fifthMerge})=>{
  write('app/Foreign.php','<?php // forbidden intermediate\n');stage();git(root,'commit','-qm','Foreign before fifth');const foreign=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const fifthHead=git(root,'rev-parse','HEAD'),policy={...fivePolicy,fifthHead};fifthMerge([fifthHead,fifthBase]);
  assert(seriesHistory(root,fifthBase,policy).includes(foreign));assert.throws(()=>fixtureIntegrationScope(root,feature,policy),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
test('public fifth policy pins consumed fourth merge, exact parent and unchanged feature head',()=>{
  const source=fs.readFileSync(new URL('../harness/series-maintenance.mjs',import.meta.url),'utf8');
  for(const [name,value] of Object.entries({approvedFourthIntegration:'27de4ee67f71dd1cf5d15d1fc0f5b976cab18d7c',approvedFourthToolingParent:'2a8273f6054627a4e50e9a89de8a927c9402b8c1',approvedFifthIntegrationHead:'27de4ee67f71dd1cf5d15d1fc0f5b976cab18d7c'}))assert(source.includes(`const ${name}='${value}';`));
});
test('exact existing Russian catalog is allowed without permitting duplicates, foreign files or renames',()=>{
  fixture(({root,write})=>{write('lang/ru/russian.php',"<?php return ['Series'=>'Сериал'];\n");assert.equal(seriesScope(root).branch,'codex/series-seasons');});
  for(const file of ['lang/ru/ru.php','lang/ru/foreign.php','lang/new/russian.php'])fixture(({root,write})=>{write(file,'<?php // unapproved\n');assert.throws(()=>seriesScope(root),/Outside approved series/);});
  fixture(({root})=>{git(root,'mv','lang/ru/russian.php','lang/ru/renamed.php');assert.throws(()=>seriesScope(root),/regular files|Outside approved series/);});
  fixture(({root,write,stage})=>{git(root,'checkout','codex/chatdev-integration');git(root,'rm','lang/ru/russian.php');git(root,'commit','-qm','Fixture parent without Russian');git(root,'push','origin','codex/chatdev-integration');git(root,'checkout','codex/series-seasons');git(root,'reset','--hard','origin/codex/chatdev-integration');write('lang/ru/russian.php','<?php // newly introduced\n');assert.throws(()=>seriesScope(root),/Outside approved series/);});
});
