#!/usr/bin/env node
// Explicit external-runtime entry; legacy feature CLI and completion stay intact.
import { fileURLToPath } from 'node:url';
import { repository } from '../harness/workflow.mjs';
import { verifyStableSeries, publishStableSeries, stableSeriesScope } from '../harness/series-maintenance.mjs';
import { acceptStableRuntime, acceptDocumentationReview, inspectStableRuntime } from './stable-series-base.mjs';

const [command,...args]=process.argv.slice(2);
try {
  const root=repository();
  if(['accept-runtime','review-upstream'].includes(command)&&args.length===1){
    const runtime=fileURLToPath(new URL('../..',import.meta.url));
    if(root!==runtime.replace(/\/$/,''))throw Error('Accept the runtime from its own tooling checkout.');
    console.log(JSON.stringify(command==='accept-runtime'?acceptStableRuntime(runtime,args[0]):acceptDocumentationReview(runtime,args[0]),null,2));
  } else if(command==='inspect-upstream'&&args.length===0){
    const runtime=fileURLToPath(new URL('../..',import.meta.url));
    if(root!==runtime.replace(/\/$/,''))throw Error('Inspect the runtime from its own tooling checkout.');
    console.log(JSON.stringify(inspectStableRuntime(runtime),null,2));
  } else if(command==='status'&&args.length===0){
    const scope=stableSeriesScope(root);
    console.log(JSON.stringify({step:8,branch:scope.branch,tooling:scope.tooling,authority:'checkpoint only; no completion, merge or step advance'},null,2));
  } else if(command==='verify'&&args.length===0)verifyStableSeries(root);
  else if(command==='checkpoint'&&args.length===2&&args[0]==='--message')publishStableSeries(root,args[1]);
  else throw Error('Usage: stable-series.mjs status|verify|checkpoint --message TEXT|accept-runtime EVIDENCE.json|inspect-upstream|review-upstream EVIDENCE.json');
} catch(error){console.error('Stable series: '+error.message);process.exitCode=1;}
