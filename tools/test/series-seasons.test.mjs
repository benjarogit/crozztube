import test from 'node:test';
import assert from 'node:assert/strict';
import { contextualQueue, nextSeriesEpisode, nextPlaybackTarget, freshSeriesTarget } from '../../resources/js/series-context.js';
const episodes = [{video_id:1,available:true,video:{id:1,slug:'first'}},{video_id:2,available:false,video:null},{video_id:3,available:true,video:{id:3,slug:'third'}},{video_id:4,available:true,video:{id:4,slug:'fourth'}}];
test('unavailable episodes are skipped without invented positions',()=>{assert.equal(nextSeriesEpisode(episodes,1).video.slug,'third');assert.equal(nextSeriesEpisode(episodes,99),null);assert.equal(nextSeriesEpisode(episodes,4),null);});
test('explicit queue survives series entry while stale generated series queue is excluded',()=>{
 const queue=[{id:2,slug:'old-series-cache',playlist_id:7},{id:9,slug:'manual'},{id:10,slug:'other-playlist',playlist_id:8},{id:1,slug:'first'}];
 assert.deepEqual(contextualQueue(queue,'first',7,true).map(v=>v.id),[9,10]);
 assert.deepEqual(contextualQueue(queue,'first',7,false).map(v=>v.id),[2]);
});
test('queued item wins over series and carries its own context',()=>{
 const target=nextPlaybackTarget({queue:[{id:9,slug:'manual'}],currentSlug:'first',playlistId:7,series:{is_series:true,episodes},videoId:1});
 assert.equal(target.video.slug,'manual');assert.equal(target.fromQueue,true);assert.equal(target.list,'');
});
test('intentional same-series queue order survives old generated queue cleanup',()=>{
 const queue=[{id:4,slug:'fourth',playlist_id:7,queue_origin:'explicit',playlist_order:'shuffle'},{id:3,slug:'third',playlist_id:7,queue_origin:'explicit',playlist_order:'shuffle'},{id:2,slug:'stale',playlist_id:7}];
 assert.deepEqual(contextualQueue(queue,'first',7,true).map(v=>v.id),[4,3]);
 assert.equal(nextPlaybackTarget({queue,currentSlug:'first',videoId:1,playlistId:7,series:{is_series:true,episodes}}).video.slug,'fourth');
});
test('series end stops instead of inventing a related episode',()=>{
 assert.equal(nextPlaybackTarget({currentSlug:'fourth',videoId:4,playlistId:7,series:{is_series:true,episodes},related:[{slug:'unrelated'}]}),null);
});
test('legacy playlist and ordinary related playback retain existing order',()=>{
 const list=[{slug:'a'},{slug:'b'}];assert.equal(nextPlaybackTarget({currentSlug:'a',playlistId:7,playlistVideos:list}).video.slug,'b');
 assert.equal(nextPlaybackTarget({currentSlug:'a',related:list}).video.slug,'b');
});
test('queue duplicates/current item never create repeated advancement',()=>{
 assert.deepEqual(contextualQueue([{slug:'a'},{slug:'b'},{slug:'b'}],'a','',true).map(v=>v.slug),['b']);
});
test('fresh resolver sends intentional queue order and retains selected playlist context',async()=>{
 let requested;
 const api={request:async path=>{requested=path;return {next_episode:{video_id:4,from_queue:true,video:{id:4,slug:'fourth'}}};}};
 const target=await freshSeriesTarget(api,{playlistId:7,currentSlug:'first',videoId:1,queue:[{id:4,slug:'fourth',playlist_id:7,queue_origin:'explicit',playlist_order:'shuffle'},{id:3,slug:'third',playlist_id:7}],shuffled:true});
 const url=new URL(requested,'http://test');assert.deepEqual(url.searchParams.getAll('queue[]'),['4']);assert.equal(target.list,7);assert.equal(target.order,'shuffle');
});
test('shuffle ends without repeating the remaining normal series sequence',async()=>{
 const api={request:async()=>({next_episode:{video_id:3,from_queue:false,video:{id:3,slug:'third'}}})};
 assert.equal(await freshSeriesTarget(api,{playlistId:7,currentSlug:'fourth',videoId:4,queue:[],shuffled:true}),null);
});
test('provider/service failure is surfaced instead of becoming an invented end',async()=>{
 await assert.rejects(()=>freshSeriesTarget({request:async()=>{throw Error('Temporary unavailable');}},{playlistId:7,currentSlug:'first',videoId:1,queue:[]}),/Temporary unavailable/);
});

test('series validation shows the translated field explanation before a generic API summary',async()=>{
 const {seriesRequestError}=await import('../../resources/js/series-context.js');
 const translated='Gib Staffel- und Folgennummer zusammen an oder lass beide Felder leer.';
 assert.equal(seriesRequestError({message:'The submitted data is invalid.',payload:{error:{message:'The submitted data is invalid.',validation:{episodes:[translated]}}}},'Fallback'),translated);
 assert.equal(seriesRequestError({message:'The given data was invalid.',payload:{errors:{episodes:[translated]}}},'Fallback'),translated);
});
test('series errors preserve conflict/network messages and safely ignore malformed validation',async()=>{
 const {seriesRequestError}=await import('../../resources/js/series-context.js');
 assert.equal(seriesRequestError({message:'Reload the playlist',payload:{error:{validation:{episodes:[null,42,' ']}}}},'Fallback'),'Reload the playlist');
 assert.equal(seriesRequestError(new Error('Network unavailable'),'Fallback'),'Network unavailable');
 assert.equal(seriesRequestError({},'Fallback'),'Fallback');
});
