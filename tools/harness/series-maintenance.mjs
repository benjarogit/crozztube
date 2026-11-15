import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { git, load, digest, indexDigest, assertStaged, localPath, localState, writeJson, setMode } from './workflow.mjs';

const branch='codex/series-seasons', parent='codex/chatdev-integration';
const approvedIntegrationHead='02455c7f647ccc34477fd4926dd349c4b6dd810e';
const approvedFirstIntegration='c17e930f33a2564c4b4deb92eba5b5792d34f64a';
const approvedFirstToolingParent='c8d403968666b789fa189e7c704a0284a3b229a5';
const approvedNextIntegrationHead='d8d4704848042b3f967f3b2be34cd4e12a0a1001';
const approvedSecondIntegration='3a07a5aaa3978b677649ae00df5235eabd79b20f';
const approvedSecondToolingParent='be06d13fcddbfc090c46ae201ddbb2ce1033ba6c';
const approvedThirdIntegrationHead='65c04875241caede38045440446af44054059df6';
const approvedThirdIntegration='88e731395587dfba5fa8dbb336fc56fa7008eb2e';
const approvedThirdToolingParent='ac406456662c78a154b445328e3539ab87468193';
const approvedFourthIntegrationHead='dfa0dc1dc43c5774e711846bac7b558f5b530cc8';
const approvedFourthIntegration='27de4ee67f71dd1cf5d15d1fc0f5b976cab18d7c';
const approvedFourthToolingParent='2a8273f6054627a4e50e9a89de8a927c9402b8c1';
const approvedFifthIntegrationHead='27de4ee67f71dd1cf5d15d1fc0f5b976cab18d7c';
const historyPolicy=Object.freeze({firstHead:approvedIntegrationHead,firstMerge:approvedFirstIntegration,firstParent:approvedFirstToolingParent,nextHead:approvedNextIntegrationHead,secondMerge:approvedSecondIntegration,secondParent:approvedSecondToolingParent,thirdHead:approvedThirdIntegrationHead,thirdMerge:approvedThirdIntegration,thirdParent:approvedThirdToolingParent,fourthHead:approvedFourthIntegrationHead,fourthMerge:approvedFourthIntegration,fourthParent:approvedFourthToolingParent,fifthHead:approvedFifthIntegrationHead});
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

// Synthetic policies are confined to this history inspector for negative tests.
// Public scope, verification and publication always use the fixed policy above.
export function seriesHistory(root,base,policy=historyPolicy){
  const commits=git(root,['rev-list',base+'..HEAD']).split('\n').filter(Boolean);
  const merges=git(root,['rev-list','--merges',base+'..HEAD']).split('\n').filter(Boolean);
  if(!merges.length)return commits;
  if(merges.length>(policy.fourthMerge?5:policy.thirdMerge?4:3)||!merges.includes(policy.firstMerge))throw Error('Merge commits are not allowed except the explicitly approved tooling integrations.');
  const validateMerge=(merge,first,second)=>{
    const parents=git(root,['show','-s','--format=%P',merge]).split(' ');
    if(parents.length!==2||parents[0]!==first||parents[1]!==second)throw Error('Merge commits must integrate the published tooling parent into the exact approved feature head.');
    let expected;
    try{expected=git(root,['merge-tree','--write-tree',first,second]).split('\n')[0];}catch{throw Error('Approved tooling integration must be conflict-free; manual resolutions are not allowed.');}
    if(git(root,['show','-s','--format=%T',merge])!==expected)throw Error('Approved tooling integration differs from the canonical merge tree.');
  };
  validateMerge(policy.firstMerge,policy.firstHead,policy.firstParent);
  try{
    git(root,['merge-base','--is-ancestor',policy.firstParent,base]);
    git(root,['merge-base','--is-ancestor',policy.firstMerge,policy.nextHead]);
  }catch{throw Error('Approved first merge and old tooling parent must retain their exact ancestry.');}
  if(merges.length===1){
    if(base!==policy.firstParent)throw Error('The moved published tooling parent requires the exact approved second integration.');
  }else if(!policy.secondMerge){
    // Synthetic older two-integration policies remain usable for isolated
    // history tests. The public gate always carries all three fixed anchors.
    if(merges.length!==2)throw Error('Merge commits are not allowed beyond the explicitly approved integrations.');
    if(base===policy.firstParent)throw Error('The second tooling integration requires an advanced published parent.');
    const next=merges.find(commit=>commit!==policy.firstMerge);
    validateMerge(next,policy.nextHead,base);
  }else{
    if(!merges.includes(policy.secondMerge))throw Error('Merge commits must retain the exact approved second integration.');
    validateMerge(policy.secondMerge,policy.nextHead,policy.secondParent);
    try{
      git(root,['merge-base','--is-ancestor',policy.firstParent,policy.secondParent]);
      git(root,['merge-base','--is-ancestor',policy.secondParent,base]);
      git(root,['merge-base','--is-ancestor',policy.secondMerge,policy.thirdHead]);
    }catch{throw Error('Approved second merge and tooling parent must retain their exact ancestry.');}
    if(merges.length===2){
      if(base!==policy.secondParent)throw Error('The moved published tooling parent requires the exact approved third integration.');
    }else if(!policy.thirdMerge){
      if(base===policy.secondParent)throw Error('The third tooling integration requires an advanced published parent.');
      const third=merges.find(commit=>commit!==policy.firstMerge&&commit!==policy.secondMerge);
      validateMerge(third,policy.thirdHead,base);
    }else{
      if(!merges.includes(policy.thirdMerge))throw Error('Merge commits must retain the exact approved third integration.');
      validateMerge(policy.thirdMerge,policy.thirdHead,policy.thirdParent);
      try{
        git(root,['merge-base','--is-ancestor',policy.secondParent,policy.thirdParent]);
        git(root,['merge-base','--is-ancestor',policy.thirdParent,base]);
      }catch{throw Error('Approved third merge and tooling parent must retain their exact ancestry.');}
      if(merges.length===3){
        if(base!==policy.thirdParent)throw Error('The moved published tooling parent requires the exact approved fourth integration.');
      }else{
        if(!/^[a-f0-9]{40}$/.test(policy.fourthHead||''))throw Error('The fourth integration awaits the confirmed approved language checkpoint.');
        try{git(root,['merge-base','--is-ancestor',policy.thirdMerge,policy.fourthHead]);}catch{throw Error('Approved third merge and fourth checkpoint must retain their exact ancestry.');}
        if(base===policy.thirdParent)throw Error('The fourth tooling integration requires an advanced published parent.');
        if(!policy.fourthMerge){
          const fourth=merges.find(commit=>![policy.firstMerge,policy.secondMerge,policy.thirdMerge].includes(commit));
          validateMerge(fourth,policy.fourthHead,base);
        }else{
          if(!merges.includes(policy.fourthMerge))throw Error('Merge commits must retain the exact approved fourth integration.');
          validateMerge(policy.fourthMerge,policy.fourthHead,policy.fourthParent);
          try{
            git(root,['merge-base','--is-ancestor',policy.thirdParent,policy.fourthParent]);
            git(root,['merge-base','--is-ancestor',policy.fourthParent,base]);
          }catch{throw Error('Approved fourth merge and tooling parent must retain their exact ancestry.');}
          if(merges.length===4){
            if(base!==policy.fourthParent)throw Error('The moved published tooling parent requires the exact approved fifth integration.');
          }else{
            if(!/^[a-f0-9]{40}$/.test(policy.fifthHead||''))throw Error('The fifth integration requires the confirmed approved feature checkpoint.');
            try{git(root,['merge-base','--is-ancestor',policy.fourthMerge,policy.fifthHead]);}catch{throw Error('Approved fourth merge and fifth checkpoint must retain their exact ancestry.');}
            if(base===policy.fourthParent)throw Error('The fifth tooling integration requires an advanced published parent.');
            const fifth=merges.find(commit=>![policy.firstMerge,policy.secondMerge,policy.thirdMerge,policy.fourthMerge].includes(commit));
            validateMerge(fifth,policy.fifthHead,base);
          }
        }
      }
    }
  }
  // Exclude only validated canonical integrations. All old feature commits,
  // including forbidden changes later reverted, still undergo path/plan audit.
  // Only imported tooling ancestry is excluded by rev-list base..HEAD.
  return commits.filter(commit=>!merges.includes(commit));
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
    || (file==='lang/en/english.php'||file==='lang/ru/russian.php'||/^lang\/([a-z0-9_]+)\/\1\.php$/.test(file))&&git(root,['ls-tree',base,'--',file]).length>0
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
