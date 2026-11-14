import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { git, load, digest, indexDigest, assertStaged, localPath, localState, writeJson, setMode } from './workflow.mjs';

const branch='codex/series-seasons', parent='codex/chatdev-integration';
const approvedIntegrationHead='02455c7f647ccc34477fd4926dd349c4b6dd810e';
const checkId='step-08-series', script='tools/test/step-08-series.sh';
const regular=(root,file)=>{const p=path.join(root,file);if(!fs.existsSync(p))return false;const s=fs.lstatSync(p);return s.isFile()&&!s.isSymbolicLink();};
const canonical=value=>JSON.stringify(value && typeof value==='object' ? Array.isArray(value)?value.map(v=>JSON.parse(canonical(v))):Object.fromEntries(Object.keys(value).sort().map(k=>[k,JSON.parse(canonical(value[k]))])) : value);
const parse=text=>{try{return JSON.parse(text);}catch{throw Error('Invalid series gate JSON; content suppressed.');}};
const remoteIdentity=root=>createHash('sha256')
  .update('fetch\0'+git(root,['remote','get-url','--all','origin']))
  .update('\0push\0'+git(root,['remote','get-url','--push','--all','origin'])).digest('hex');
const requiredArgv=['bash',script];
const productPaths=new Set([
  'app/Support/SharedPlaylists.php','app/Support/SeriesPlaylists.php','app/Policies/PlaylistPolicy.php',
  'app/Models/Playlist.php','app/Models/PlaylistSeason.php','app/Models/PlaylistEpisode.php',
  'app/Models/PlaylistVideo.php','app/Http/Resources/PlaylistResource.php',
  'app/Http/Resources/Api/V1/PlaylistSummaryResource.php',
  'app/Http/Controllers/Api/V1/PlaylistController.php','app/Http/Controllers/Api/SharedPlaylistController.php',
  'app/Http/Controllers/Api/SeriesPlaylistController.php',
  'routes/api.php','routes/api_v1.php','resources/js/main.jsx','resources/js/components/PlaylistSeries.jsx',
  'resources/js/playlistSeries.js','resources/js/series-context.js','resources/js/styles/playlist-series.css',
  'docs/series-seasons.md','build/manifest.json',script,
  'tools/test/series-seasons.test.mjs','tools/test/php/series-seasons-checks.php','tools/test/php/series-playlist-checks.php',
  'tools/test/ui/series-seasons.mjs',
]);

function validatePlan(candidate,baseline){
  const next=structuredClone(candidate), previous=structuredClone(baseline);
  const added=next.checks?.[checkId], existing=previous.checks?.[checkId];
  if(added && !existing){
    if(Object.keys(added).some(k=>!['argv','timeoutMs'].includes(k))||canonical(added.argv)!==canonical(requiredArgv)||added.timeoutMs!==undefined&&(!Number.isSafeInteger(added.timeoutMs)||added.timeoutMs<1000||added.timeoutMs>600000))throw Error('Only the approved real step-08-series command may be added.');
    delete next.checks[checkId];
  }
  if(canonical(next)!==canonical(previous))throw Error('Series gate cannot change existing plan, checks, step order or checkpoint requirements.');
}

function assetNames(manifest){
  const result=new Set();
  for(const entry of Object.values(manifest))for(const file of [entry?.file,...entry?.css||[],...entry?.assets||[]]){
    if(!file)continue;
    if(typeof file!=='string'||!/^assets\/[a-zA-Z0-9_./-]+\.(?:js|css|wasm|svg|png|webp|woff2?)$/.test(file)||file.split('/').includes('..'))throw Error('Unsafe release asset reference.');
    result.add('build/'+file);
  }
  return result;
}

// The test anchor parameter is confined to this history inspector. Public
// scope/verify/publication always use the fixed, explicitly approved head.
export function seriesHistory(root,base,approvedHead=approvedIntegrationHead){
  const commits=git(root,['rev-list',base+'..HEAD']).split('\n').filter(Boolean);
  const merges=git(root,['rev-list','--merges',base+'..HEAD']).split('\n').filter(Boolean);
  if(!merges.length)return commits;
  if(merges.length!==1)throw Error('Merge commits are not allowed except the approved tooling integration.');
  const merge=merges[0], parents=git(root,['show','-s','--format=%P',merge]).split(' ');
  if(parents.length!==2||parents[0]!==approvedHead||parents[1]!==base)throw Error('Merge commits must integrate the published tooling parent into the exact approved feature head.');
  let expected;
  try{expected=git(root,['merge-tree','--write-tree',approvedHead,base]).split('\n')[0];}catch{throw Error('Approved tooling integration must be conflict-free; manual resolutions are not allowed.');}
  if(git(root,['show','-s','--format=%T',merge])!==expected)throw Error('Approved tooling integration differs from the canonical merge tree.');
  // Exclude only this validated merge. Old feature commits remain in the list,
  // including changes reverted before integration; imported tooling ancestry
  // is excluded by rev-list base..HEAD rather than a broad path exemption.
  return commits.filter(commit=>commit!==merge);
}

export function seriesScope(root){
  const context=load(root);
  if(git(root,['branch','--show-current'])!==branch)throw Error('Use dedicated codex/series-seasons branch.');
  if(context.step.id!==8||context.record.status!=='active')throw Error('Only active step 8 can use the series checkpoint.');
  if(context.plan.remote!=='origin'||context.plan.branch!=='main')throw Error('Unexpected authoritative branch or remote.');
  const base=git(root,['rev-parse',`origin/${parent}`]);
  git(root,['merge-base','--is-ancestor',base,'HEAD']);
  const commits=seriesHistory(root,base);
  if(fs.existsSync(path.resolve(root,git(root,['rev-parse','--git-path','MERGE_HEAD']))))throw Error('Pending merge is outside the series checkpoint.');
  const baseline=parse(git(root,['show',`${base}:harness/plan.json`]));
  validatePlan(context.plan,baseline);
  const changed=git(root,['diff','--name-only','-z',base,'--']).split('\0').filter(Boolean);
  const untracked=git(root,['ls-files','--others','--exclude-standard','-z']).split('\0').filter(Boolean);
  const historical=[];
  for(const commit of commits){
    historical.push(...git(root,['diff-tree','--no-commit-id','--name-only','-r','-z',commit]).split('\0').filter(Boolean));
    validatePlan(parse(git(root,['show',`${commit}:harness/plan.json`])),baseline);
  }
  const released=assetNames(parse(fs.readFileSync(path.join(root,'build/manifest.json'),'utf8')));
  // Keep prior lazy chunks available for already mounted players/forms. Every
  // extra asset must have been referenced by an actual release manifest.
  const historicalAssets=new Set();
  for(const commit of [base,...commits])for(const file of assetNames(parse(git(root,['show',`${commit}:build/manifest.json`]))))historicalAssets.add(file);
  const allowed=file=>productPaths.has(file)||file==='harness/plan.json'
    || /^database\/migrations\/\d{4}_\d{2}_\d{2}_\d{6}_(?:create_playlist_(?:seasons|episodes)|add_series_(?:structure|context)_to_playlists)\.php$/.test(file)
    || (file==='lang/en/english.php'||/^lang\/([a-z0-9_]+)\/\1\.php$/.test(file))&&git(root,['ls-tree',base,'--',file]).length>0
    || released.has(file)||historicalAssets.has(file);
  const forbidden=[...changed,...untracked,...historical].filter(file=>!allowed(file));
  if(forbidden.length)throw Error('Outside approved series scope: '+[...new Set(forbidden)].join(', '));
  for(const file of [...changed,...untracked])if(!regular(root,file))throw Error('Series changes must remain regular files; no deletion or symlink: '+file);
  const indexedAssets=new Set(git(root,['ls-files','--stage','-z']).split('\0').filter(Boolean).flatMap(entry=>{
    const match=entry.match(/^(100644|100755) [a-f0-9]+ 0\t(.+)$/);return match?[match[2]]:[];
  }));
  for(const file of released){
    if(!regular(root,file))throw Error('Referenced release asset is missing or not regular: '+file);
    if(!indexedAssets.has(file))throw Error('Referenced release asset must be reviewed and tracked/staged in the index: '+file);
  }
  const ids=baseline.steps.find(s=>s.id===8)?.checkpointChecks;
  if(!Array.isArray(ids)||!ids.length)throw Error('Existing step-8 checkpoint checks are required.');
  if(context.plan.checks?.[checkId]&&!regular(root,script))throw Error('The real series check script is missing.');
  return {branch,base,parent,context,baseline,checks:context.plan.checks?.[checkId]?[...new Set([...ids,checkId])]:ids,remoteIdentity:remoteIdentity(root)};
}

function publishedParent(root,scope){
  let remote;
  try{remote=git(root,['ls-remote','--exit-code','--heads','origin',`refs/heads/${parent}`]).split(/\s+/)[0];}catch{throw Error('Published tooling parent could not be verified.');}
  if(remote!==scope.base)throw Error('Published tooling parent moved; refresh and review the series base.');
}

export function verifySeries(root){
  const scope=seriesScope(root);assertStaged(root);publishedParent(root,scope);
  const receipt=localPath(root,'series-verification.json');fs.rmSync(receipt,{force:true});
  const before=digest(root), index=indexDigest(root), results=[];
  for(const id of scope.checks){
    const check=scope.context.plan.checks?.[id];
    if(!check?.argv?.length||!check.argv.every(arg=>typeof arg==='string'&&arg.length))throw Error('An existing series checkpoint check is not implemented: '+id);
    if(check.timeoutMs!==undefined&&(!Number.isSafeInteger(check.timeoutMs)||check.timeoutMs<1||check.timeoutMs>600000))throw Error('Checkpoint timeout must be bounded to ten minutes: '+id);
    console.log('Checking '+id+'…');
    const result=spawnSync(check.argv[0],check.argv.slice(1),{cwd:root,stdio:'inherit',timeout:check.timeoutMs||600000});
    if(result.status!==0){setMode(root,'blocked','Series checkpoint check failed: '+id);throw Error('Series check failed; no verified publication: '+id);}
    results.push({id,passed:true});
  }
  seriesScope(root);publishedParent(root,scope);
  if(digest(root)!==before||indexDigest(root)!==index)throw Error('Checks changed the reviewed series snapshot.');
  writeJson(receipt,{purpose:'series-checkpoint-only',step:8,branch,base:scope.base,remoteIdentity:scope.remoteIdentity,digest:before,indexDigest:index,checks:results,checkedAt:new Date().toISOString()});
  console.log('PASS: bounded series checkpoint verified; no feature accepted or advanced.');
}

export function publishSeries(root,message){
  if(localState(root).mode!=='implementing')throw Error('Resume before publishing the series checkpoint.');
  const scope=seriesScope(root);assertStaged(root);
  const receipt=localPath(root,'series-verification.json');if(!fs.existsSync(receipt))throw Error('Run series-verify first.');
  const proof=parse(fs.readFileSync(receipt,'utf8'));
  if(proof.purpose!=='series-checkpoint-only'||proof.step!==8||proof.branch!==branch||proof.base!==scope.base||proof.remoteIdentity!==scope.remoteIdentity||proof.digest!==digest(root)||proof.indexDigest!==indexDigest(root)||scope.checks.some(id=>!proof.checks?.some(c=>c.id===id&&c.passed)))throw Error('Series proof is stale or incomplete.');
  if(!message?.trim())throw Error('A series checkpoint message is required.');
  publishedParent(root,scope);
  if(git(root,['diff','--cached','--name-only']))git(root,['commit','-m',message]);
  try{
    git(root,['push','--set-upstream','origin',`HEAD:refs/heads/${branch}`]);
    const head=git(root,['rev-parse','HEAD']), remote=git(root,['ls-remote','--exit-code','--heads','origin',`refs/heads/${branch}`]).split(/\s+/)[0];
    if(head!==remote)throw Error('Unconfirmed series publication.');
    writeJson(localPath(root,'series-publication.json'),{purpose:'series-checkpoint-only',step:8,branch,base:scope.base,commit:head,checkedAt:proof.checkedAt});
    setMode(root,'discussion','Series checkpoint committed and confirmed pushed; step 8 remains open.');
    console.log('Confirmed series checkpoint push: '+head);
  }catch{
    setMode(root,'blocked','Series commit exists but remote publication could not be confirmed.');
    throw Error('Series push or confirmation failed; resume and retry the same checkpoint.');
  }
}
