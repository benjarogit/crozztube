<?php

// A new, in-memory Laravel database. No application bootstrap, .env, account,
// provider request, media bytes or running application database is accessed.
require __DIR__.'/../../../vendor/autoload.php';

use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\SeriesPlaylists;
use App\Support\SharedPlaylists;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application(dirname(__DIR__, 3));
$app->instance('config', new Repository(['app' => ['locale' => 'en', 'timezone' => 'UTC']]));
$events = new Dispatcher($app);
$app->instance('events', $events);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setEventDispatcher($events);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', fn () => $capsule->schema());
$app->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $app));
$app->instance('auth', new class implements \Illuminate\Contracts\Auth\Factory {
    public function guard($name = null) { return new \Illuminate\Auth\RequestGuard(fn () => null, new Request); }
    public function shouldUse($name) {}
});
Facade::setFacadeApplication($app);
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class extends \Illuminate\Routing\ResponseFactory { public function __construct() {} });
Request::macro('validate', function (array $rules): array { return app('validator')->make($this->all(),$rules)->validate(); });
$request = Request::create('/api/playlists/1/series', 'GET');
$viewer = null;
$request->setUserResolver(function () use (&$viewer) { return $viewer; });
$app->instance('request', $request);
$app->instance(\Illuminate\Contracts\Auth\Access\Gate::class, new \Illuminate\Auth\Access\Gate($app, function () use (&$viewer) { return $viewer; }));
Gate::policy(Playlist::class, \App\Policies\PlaylistPolicy::class);

Schema::create('users', function (Blueprint $t): void { $t->id(); $t->string('name'); $t->string('username'); $t->text('settings')->nullable(); $t->timestamps(); });
Schema::create('playlists', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('channel_id')->nullable(); $t->string('title'); $t->text('description')->nullable(); $t->string('color')->default('#6c4cff'); $t->string('privacy')->default('public'); $t->timestamps(); });
Schema::create('videos', function (Blueprint $t): void {
    $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('channel_id')->nullable(); $t->string('title'); $t->string('slug')->unique(); $t->string('visibility')->default('public'); $t->string('status')->default('ready'); $t->string('approval_status')->nullable(); $t->timestamp('scheduled_at')->nullable(); $t->boolean('sale_enabled')->default(false); $t->integer('price_cents')->nullable(); $t->boolean('is_short')->default(false); $t->unsignedInteger('duration_seconds')->default(10); $t->string('playlist_title')->nullable(); $t->timestamps();
});
Schema::create('playlist_video', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('playlist_id'); $t->unsignedBigInteger('video_id'); $t->unsignedBigInteger('added_by_user_id')->nullable(); $t->unsignedInteger('sort_order')->default(0); $t->timestamps(); $t->unique(['playlist_id', 'video_id']); });
Schema::create('playlist_members', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('playlist_id'); $t->unsignedBigInteger('user_id'); $t->string('role'); $t->timestamp('accepted_at')->nullable(); $t->timestamps(); });
Schema::create('playlist_video_votes', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('playlist_id'); $t->unsignedBigInteger('video_id'); $t->unsignedBigInteger('user_id'); $t->timestamps(); });
Schema::create('user_blocks', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('blocked_user_id'); });
Schema::create('video_purchases', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('buyer_id'); $t->unsignedBigInteger('video_id'); $t->string('status'); });
$migration = require __DIR__.'/../../../database/migrations/2026_11_14_000001_add_series_context_to_playlists.php';
$migration->up();
$checks = 0;
$check = function (bool $ok, string $message) use (&$checks): void { if (! $ok) throw new RuntimeException($message); $checks++; };
$reject = function (callable $fn, int $status) use ($check): void { try { $fn(); } catch (ValidationException $e) { $check($status === 422, 'Expected validation response'); return; } catch (HttpException $e) { $check($e->getStatusCode() === $status, 'Expected HTTP '.$status); return; } throw new RuntimeException('Expected rejection '.$status); };
foreach ([1 => 'owner', 2 => 'editor', 3 => 'viewer', 4 => 'other-owner', 5 => 'outsider'] as $id => $name) DB::table('users')->insert(['id' => $id, 'name' => $name, 'username' => $name]);
$owner = User::findOrFail(1); $editor = User::findOrFail(2); $member = User::findOrFail(3); $outsider = User::findOrFail(5);
$playlist = Playlist::create(['user_id' => 1, 'title' => 'Series fixture', 'privacy' => 'public']);
$other = Playlist::create(['user_id' => 1, 'title' => 'Other series', 'privacy' => 'public']);
$check(! $playlist->is_series, 'Existing playlists default to ordinary playlists');
foreach (range(1, 10) as $id) {
    DB::table('videos')->insert(['id' => $id, 'user_id' => 4, 'slug' => 'episode-'.$id, 'title' => 'Video '.$id, 'playlist_title' => 'Original creator title']);
    DB::table('playlist_video')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'sort_order' => $id - 1]);
}
DB::table('playlist_video')->insert(['playlist_id' => $other->id, 'video_id' => 1, 'sort_order' => 0]);
foreach ([[2,'editor'],[3,'viewer']] as [$id,$role]) DB::table('playlist_members')->insert(['playlist_id' => $playlist->id, 'user_id' => $id, 'role' => $role, 'accepted_at' => now()]);
$viewer = $owner;
$revision = SeriesPlaylists::revision($playlist);
$payload = SeriesPlaylists::update($owner, $playlist, ['expected_revision' => $revision, 'is_series' => true, 'episodes' => [
    ['video_id'=>1,'season_number'=>1,'episode_number'=>1,'episode_title'=>' Opening '], ['video_id'=>2,'season_number'=>1,'episode_number'=>2], ['video_id'=>3,'season_number'=>2,'episode_number'=>1],
]]);
$check($payload['is_series'] && count($payload['episodes']) === 10, 'Enabling series keeps existing videos');
$check(is_string($payload['series_revision']) && strlen($payload['series_revision']) === 64, 'Owner retains optimistic revision');
$check(SeriesPlaylists::payload($playlist,$member)['series_revision'] === null, 'Members receive no hidden-membership revision');
$check(SeriesPlaylists::payload($playlist,null)['series_revision'] === null, 'Guests receive no hidden-membership revision');
$check($payload['episodes'][0]['episode_title'] === 'Opening', 'Trim context title');
$controller = new \App\Http\Controllers\Api\SeriesPlaylistController;
foreach (['/api','/api/v1'] as $prefix) {
    $readRequest = Request::create($prefix.'/playlists/'.$playlist->id.'/series','GET');
    $readRequest->setUserResolver(fn () => null);
    $response = $controller->show($readRequest,$playlist);
    $check($response->getStatusCode() === 200 && $response->getData(true)['is_series'], 'Both surfaces share public series response');
    $nextRequest = Request::create($prefix.'/playlists/'.$playlist->id.'/series/next','GET',['after'=>1]);
    $nextRequest->setUserResolver(fn () => null);
    $response = $controller->next($nextRequest,$playlist);
    $check($response->getData(true)['next_episode']['video_id'] === 2, 'Both surfaces share next resolver');
}
$badQueueRequest = Request::create('/api/playlists/'.$playlist->id.'/series/next','GET',['after'=>1,'queue'=>range(1,51)]);
$badQueueRequest->setUserResolver(fn () => null);
$reject(fn () => $controller->next($badQueueRequest,$playlist),422);
$writeRequest = Request::create('/api/playlists/'.$playlist->id.'/series','PUT',['expected_revision'=>SeriesPlaylists::revision($playlist),'is_series'=>true]);
$writeRequest->setUserResolver(fn () => $editor);
$reject(fn () => $controller->update($writeRequest,$playlist),403);
$writeRequest->setUserResolver(fn () => null);
$reject(fn () => $controller->update($writeRequest,$playlist),401);
try {
    DB::table('playlist_video')->where('playlist_id',$playlist->id)->where('video_id',9)->update(['season_number'=>1,'episode_number'=>1]);
    throw new RuntimeException('Database must enforce episode identity uniqueness');
} catch (\Illuminate\Database\QueryException $e) {
    $check(str_contains(strtolower($e->getMessage()), 'unique'), 'Unique database index guards episode identity');
}
$check($payload['seasons'][0]['video_ids'] === [1,2] && $payload['seasons'][1]['video_ids'] === [3], 'Seasons derive from pivot metadata');
$check(SeriesPlaylists::payload($other, $owner)['episodes'][0]['season_number'] === null, 'Shared video has independent series metadata');
$check(DB::table('videos')->where('id',1)->value('playlist_title') === 'Original creator title', 'Preserve creator originals');
$check(DB::table('playlist_video')->where('playlist_id',$playlist->id)->orderBy('sort_order')->pluck('video_id')->all() === range(1,10), 'Metadata never reorders');
foreach ([$editor,$member,$outsider] as $actor) $reject(fn () => SeriesPlaylists::update($actor,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'is_series'=>false]),403);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>$revision,'is_series'=>false]),409);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'episodes'=>[['video_id'=>2,'season_number'=>1,'episode_number'=>1]]]),422);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'episodes'=>[['video_id'=>3,'season_number'=>null]]]),422);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'episodes'=>[['video_id'=>999,'season_number'=>1,'episode_number'=>3]]]),422);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'episodes'=>[['video_id'=>1],['video_id'=>1]]]),422);
$swapped = SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'episodes'=>[['video_id'=>1,'episode_number'=>2],['video_id'=>2,'episode_number'=>1]]]);
$check($swapped['episodes'][0]['episode_number'] === 2 && $swapped['episodes'][1]['episode_number'] === 1, 'Swap numbering atomically');
$revision = SeriesPlaylists::revision($playlist);
SharedPlaylists::reorder($owner,$playlist,[2,1,3,4,5,6,7,8,9,10]);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>$revision,'episodes'=>[['video_id'=>1,'episode_title'=>'stale']]]),409);
$check(SeriesPlaylists::next($playlist,$owner,2)['video_id'] === 1, 'Next follows manual order, not episode numbers');
$check(SeriesPlaylists::next($playlist,$owner,10) === null, 'No wrap at final episode');
DB::table('playlist_video')->where('playlist_id',$playlist->id)->whereIn('video_id',[1,2])->update(['sort_order'=>0]);
$check(array_column(array_slice(SeriesPlaylists::payload($playlist,$owner)['episodes'],0,2),'video_id') === [1,2], 'Tied positions use pivot ID consistently with revision');
$check(SeriesPlaylists::next($playlist,$owner,1)['video_id'] === 2, 'Next resolver uses the same pivot tie-break');
SharedPlaylists::reorder($owner,$playlist,[2,1,3,4,5,6,7,8,9,10]);
// Execute the actual legacy transaction block in this isolated application.
// This checks its rollback/order contract without loading application routes or .env.
$routeSource = file_get_contents(__DIR__.'/../../../routes/api.php');
$start = strpos($routeSource, '        $playlist = DB::transaction(function () use ($request, $playlist, $data): Playlist {');
$end = strpos($routeSource, "\n        return response()->json([", $start);
if ($start === false || $end === false) throw new RuntimeException('Legacy transactional writer not found');
$transactionSource = substr($routeSource,$start,$end-$start);
$mutateLegacy = static function (Request $request, Playlist $playlist, array $data) use ($transactionSource): Playlist { eval('use App\Models\Playlist; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Gate; use Illuminate\Support\Facades\Schema; '.$transactionSource); return $playlist; };
$legacyRequest = Request::create('/api/playlists/'.$playlist->id,'PUT');
$legacyRequest->setUserResolver(fn () => $owner);
$legacyBefore = SeriesPlaylists::revision($playlist);
$mutateLegacy($legacyRequest,$playlist,['title'=>'Legacy title edit']);
$check(DB::table('playlist_video')->where('playlist_id',$playlist->id)->orderBy('sort_order')->pluck('video_id')->all() === [2,1,3,4,5,6,7,8,9,10], 'Legacy scalar update preserves manual order');
$check(DB::table('playlist_video')->where('playlist_id',$playlist->id)->where('video_id',1)->value('episode_title') === 'Opening', 'Legacy sync preserves episode metadata');
$rollbackBefore = SeriesPlaylists::revision($playlist);
$titleBefore = Playlist::findOrFail($playlist->id)->title;
$rejectPivot = true;
DB::listen(function ($query) use (&$rejectPivot): void {
    if ($rejectPivot && str_contains($query->sql,'playlist_video') && preg_match('/^(delete|update|insert) /i',$query->sql)) {
        $rejectPivot = false;
        throw new RuntimeException('Synthetic pivot failure');
    }
});
try {
    $mutateLegacy($legacyRequest,$playlist,['title'=>'Must roll back','video_ids'=>[8,10]]);
    throw new RuntimeException('Expected pivot failure');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'Synthetic pivot failure', 'Actual legacy sync raises injected failure');
}
$check(Playlist::findOrFail($playlist->id)->title === $titleBefore && SeriesPlaylists::revision($playlist) === $rollbackBefore, 'Legacy scalar and membership writes roll back atomically');
$mutateLegacy($legacyRequest,$playlist,['title'=>'New sequence','video_ids'=>[1,2,3,4,5,6,7,8,9,10]]);
$reject(fn () => SeriesPlaylists::update($owner,$playlist,['expected_revision'=>$rollbackBefore,'is_series'=>false]),409);
SharedPlaylists::reorder($owner,$playlist,[2,1,3,4,5,6,7,8,9,10]);

$viewer = null;
$check(count(SeriesPlaylists::payload($playlist,null)['episodes']) === 10, 'Public series supports guests');
// Explicit queue candidates are freshly authorized, ordered and bounded.
foreach (range(101,107) as $id) DB::table('videos')->insert(['id'=>$id,'user_id'=>5,'slug'=>'queue-'.$id,'title'=>'Queue '.$id]);
DB::table('videos')->where('id',102)->update(['visibility'=>'private']);
DB::table('videos')->where('id',103)->update(['sale_enabled'=>true,'price_cents'=>100]);
DB::table('videos')->where('id',104)->update(['status'=>'processing']);
DB::table('videos')->where('id',105)->update(['scheduled_at'=>now()->addDay()]);
DB::table('videos')->where('id',106)->update(['approval_status'=>'rejected']);
DB::table('videos')->where('id',107)->update(['user_id'=>3,'visibility'=>'private']);
$viewer = $member;
$queued = SeriesPlaylists::next($playlist,$member,2,[101]);
$check($queued['video_id'] === 101 && $queued['from_queue'] && $queued['sort_order'] === null && $queued['season_number'] === null, 'Explicit external queue wins without fabricated episode metadata');
$check(SeriesPlaylists::next($playlist,$member,2,[107,101])['video_id'] === 107, 'Queue order and own private access are respected');
$fallback = SeriesPlaylists::next($playlist,$member,2,[9999,102,103,104,105,106]);
$check($fallback['video_id'] === 1 && ! $fallback['from_queue'], 'Unavailable queue entries expose no metadata and fall back to series');
$check(SeriesPlaylists::next($playlist,$member,2,[2,101])['video_id'] === 101, 'Do not replay current video from queue');
$check(SeriesPlaylists::next($playlist,$member,2,[10])['video_id'] === 10, 'Explicit in-series queue also outranks following episode');
DB::table('user_blocks')->insert(['user_id'=>3,'blocked_user_id'=>5]);
$check(SeriesPlaylists::next($playlist,$member,2,[101,107])['video_id'] === 107, 'Blocked queued author is skipped');
DB::table('user_blocks')->where('user_id',3)->where('blocked_user_id',5)->delete();
DB::table('video_purchases')->insert(['buyer_id'=>3,'video_id'=>103,'status'=>'completed']);
$check(SeriesPlaylists::next($playlist,$member,2,[103])['video_id'] === 103, 'Purchased queued video can play');
DB::table('video_purchases')->where('video_id',103)->delete();
$reject(fn () => SeriesPlaylists::next($playlist,$member,9999,[101]),404);
$reject(fn () => SeriesPlaylists::next($playlist,$member,2,range(101,151)),422);
$reject(fn () => SeriesPlaylists::next($playlist,$member,2,[101,101]),422);
DB::table('videos')->where('id','>=',101)->delete();
$viewer = null;
DB::table('videos')->where('id',3)->update(['visibility'=>'private']);
DB::table('videos')->where('id',4)->update(['status'=>'processing']);
DB::table('videos')->where('id',5)->update(['scheduled_at'=>now()->addDay()]);
DB::table('videos')->where('id',6)->update(['approval_status'=>'rejected']);
DB::table('videos')->where('id',7)->update(['sale_enabled'=>true,'price_cents'=>100]);
$check(SeriesPlaylists::next($playlist,null,1)['video_id'] === 8, 'Skip private, processing, scheduled, rejected and paid videos');
$reject(fn () => SeriesPlaylists::next($playlist,null,3),404);
$reject(fn () => SeriesPlaylists::next($playlist,null,999),404);
$viewer = $member;
DB::table('video_purchases')->insert(['buyer_id'=>3,'video_id'=>7,'status'=>'completed']);
$check(SeriesPlaylists::next($playlist,$member,1)['video_id'] === 7, 'Purchased episode is available');
DB::table('user_blocks')->insert(['user_id'=>3,'blocked_user_id'=>4]);
$check(SeriesPlaylists::payload($playlist,$member)['episodes'] === [], 'Blocked author episodes are excluded');
$reject(fn () => SeriesPlaylists::next($playlist,$member,1),404);
DB::table('user_blocks')->delete();
$member->settings = ['bans' => ['watch' => ['forever' => true]]];
$check(SeriesPlaylists::payload($playlist,$member)['episodes'] === [], 'Watch bans suppress available episodes');
$member->settings = [];
$outsider->settings = ['preferences' => ['publicProfile' => false]];
$outsider->save();
DB::table('videos')->where('id',8)->update(['user_id'=>5]);
$check(SeriesPlaylists::next($playlist,$member,1)['video_id'] === 7, 'Private creator profile never bypasses prior purchased episode');
DB::table('video_purchases')->delete();
$check(SeriesPlaylists::next($playlist,$member,1)['video_id'] === 9, 'Private profile episodes are skipped');
$profileSnapshot = $playlist->fresh()->load('user');
DB::table('users')->where('id',1)->update(['settings'=>json_encode(['preferences'=>['publicProfile'=>false]])]);
$viewer = null;
$reject(fn () => SeriesPlaylists::payload($profileSnapshot,null),404);
$reject(fn () => SeriesPlaylists::next($profileSnapshot,null,2),404);
DB::table('users')->where('id',1)->update(['settings'=>null]);
$stalePublicRoute = $playlist->fresh();
$playlist->update(['privacy'=>'private']);
$viewer = null;
$reject(fn () => SeriesPlaylists::payload($playlist,null),404);
$reject(fn () => SeriesPlaylists::payload($stalePublicRoute,null),404);
$reject(fn () => SeriesPlaylists::next($stalePublicRoute,null,2),404);
$viewer = $outsider;
$reject(fn () => SeriesPlaylists::payload($playlist,$outsider),404);
$viewer = $member;
$check(count(SeriesPlaylists::payload($playlist,$member)['episodes']) > 0, 'Accepted viewer can read private series');
$viewer = $owner;
$before = DB::table('playlist_video')->where('playlist_id',$playlist->id)->where('video_id',1)->first();
SeriesPlaylists::update($owner,$playlist,['expected_revision'=>SeriesPlaylists::revision($playlist),'is_series'=>false]);
$after = DB::table('playlist_video')->where('playlist_id',$playlist->id)->where('video_id',1)->first();
$check($before->episode_title === $after->episode_title && $before->episode_number === $after->episode_number, 'Disabling preserves metadata');
$check(SeriesPlaylists::next($playlist,$owner,1) === null, 'Ordinary playlist does not auto-resolve episodes');
$migration->down();
$check(! Schema::hasColumn('playlists','is_series') && ! Schema::hasColumn('playlist_video','season_number'), 'Migration rollback succeeds');
$check(DB::table('playlist_video')->count() === 11 && DB::table('videos')->count() === 10, 'Rollback preserves existing content');
$migration->up();
$check(! Playlist::findOrFail($playlist->id)->is_series, 'Reapply has safe defaults');
echo "Series playlist checks passed: {$checks}; isolated SQLite memory, no app environment or media access.\n";
