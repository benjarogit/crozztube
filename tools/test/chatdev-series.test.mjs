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
  for(const [key,value] of Object.entries({approvedIntegrationHead:policy.firstHead,approvedFirstIntegration:policy.firstMerge,approvedFirstToolingParent:policy.firstParent,approvedNextIntegrationHead:policy.nextHead}))source=source.replace(new RegExp("const "+key+"='[a-f0-9]{40}';"),"const "+key+"='"+value+"';");
  source=source.replace("'./workflow.mjs'",JSON.stringify(new URL('../harness/workflow.mjs',import.meta.url).href));
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
    assert.throws(()=>seriesHistory(root,newBase,policy),/exact approved feature head|two explicitly approved/);
  });
});
test('second integration rejects injected tree, forged first merge identity and any third merge',()=>{
  twoIntegrationFixture(({root,write,stage,newBase,policy,secondMerge})=>{write('app/Foreign.php','<?php // injected\n');stage();secondMerge(undefined,git(root,'write-tree'));assert.throws(()=>seriesHistory(root,newBase,policy),/canonical merge tree/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{secondMerge();assert.throws(()=>seriesHistory(root,newBase,{...policy,firstMerge:policy.firstHead}),/two explicitly approved/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{const second=secondMerge(),tree=git(root,'write-tree'),side=git(root,'commit-tree',tree,'-p',second,'-m','Side feature'),third=git(root,'commit-tree',tree,'-p',second,'-p',side,'-m','Unauthorized third');git(root,'reset','--hard',third);assert.throws(()=>seriesHistory(root,newBase,policy),/two explicitly approved/);});
});
test('two integrations require preserved first merge and tooling-parent ancestry',()=>{
  twoIntegrationFixture(({root,feature,newBase,policy,secondMerge})=>{secondMerge();assert.throws(()=>seriesHistory(root,newBase,{...policy,nextHead:feature}),/retain their exact ancestry/);});
  twoIntegrationFixture(({root,newBase,policy,secondMerge})=>{secondMerge();const other=git(root,'commit-tree',git(root,'write-tree'),'-m','Unrelated parent');assert.throws(()=>seriesHistory(root,other,policy),/retain their exact ancestry/);});
});
test('foreign edits reverted between integrations remain forbidden by full historical scope',()=>twoIntegrationFixture(({root,feature,write,stage,newBase,policy,secondMerge})=>{
  write('app/Foreign.php','<?php // forbidden intermediate\n');stage();git(root,'commit','-qm','Forbidden intermediate');const forbidden=git(root,'rev-parse','HEAD');git(root,'revert','--no-edit','HEAD');const nextHead=git(root,'rev-parse','HEAD'),updated={...policy,nextHead};secondMerge([nextHead,newBase],git(root,'merge-tree','--write-tree',nextHead,newBase).split('\n')[0]);
  assert(seriesHistory(root,newBase,updated).includes(forbidden));assert.throws(()=>fixtureIntegrationScope(root,feature,updated),error=>error.stderr?.toString().includes('Outside approved series scope: app/Foreign.php'));
}));
test('exact existing Russian catalog is allowed without permitting duplicates, foreign files or renames',()=>{
  fixture(({root,write})=>{write('lang/ru/russian.php',"<?php return ['Series'=>'Сериал'];\n");assert.equal(seriesScope(root).branch,'codex/series-seasons');});
  for(const file of ['lang/ru/ru.php','lang/ru/foreign.php','lang/new/russian.php'])fixture(({root,write})=>{write(file,'<?php // unapproved\n');assert.throws(()=>seriesScope(root),/Outside approved series/);});
  fixture(({root})=>{git(root,'mv','lang/ru/russian.php','lang/ru/renamed.php');assert.throws(()=>seriesScope(root),/regular files|Outside approved series/);});
  fixture(({root,write,stage})=>{git(root,'checkout','codex/chatdev-integration');git(root,'rm','lang/ru/russian.php');git(root,'commit','-qm','Fixture parent without Russian');git(root,'push','origin','codex/chatdev-integration');git(root,'checkout','codex/series-seasons');git(root,'reset','--hard','origin/codex/chatdev-integration');write('lang/ru/russian.php','<?php // newly introduced\n');assert.throws(()=>seriesScope(root),/Outside approved series/);});
});
