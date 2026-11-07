<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    $safe = $error instanceof Illuminate\Database\QueryException ? 'SQL '.(int) ($error->errorInfo[1] ?? 0)
        : ($error instanceof RuntimeException ? $error->getMessage() : get_class($error));
    fwrite(STDERR, 'Clip confirmation checks failed: '.$safe."\n"); exit(1);
});

use App\Http\Controllers\Api\ClipTransferController;
use App\Models\ClipTransfer;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Push\PushDeliveryService;
use App\Support\Clips\ClipTransferGateway;
use App\Support\Clips\ClipTransferProcessor;
use App\Support\Clips\ClipTransferQueue;
use App\Support\Clips\OdyseeImportFailure;
use App\Support\Clips\OdyseePublishingFailure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ConfirmationPushFixture extends PushDeliveryService
{
    public int $sent = 0;
    public function __construct() {}
    public function enqueue(UserNotification $notification): void { DB::afterCommit(fn () => $this->sent++); }
}
final class ConfirmationGatewayFixture implements ClipTransferGateway
{
    public bool $playable = false;
    public bool $published = true;
    public int $seals = 0, $registrations = 0, $creates = 0, $publicChanges = 0, $deletions = 0, $cleanups = 0;
    public mixed $onAvailable = null, $onRegister = null, $onPublication = null, $onAbandon = null;
    public function publisherChannelId(): string { return str_repeat('a', 40); }
    public function seal(ClipTransfer $transfer): void { $this->seals++; }
    public function register(ClipTransfer $transfer): string {
        $this->registrations++;
        if ($this->onRegister) ($this->onRegister)($transfer);
        return 'https://uploads-v4.cluster-alpha.odysee.com/v1/urls/'.str_repeat('b', 32);
    }
    public function publish(ClipTransfer $transfer): string { $this->creates++; return str_repeat('c', 32).'1794074400123'; }
    public function publication(ClipTransfer $transfer): ?string {
        if ($this->onPublication) ($this->onPublication)($transfer);
        return $this->published ? str_repeat('d', 40) : null;
    }
    public function available(ClipTransfer $transfer): bool {
        if ($this->onAvailable) ($this->onAvailable)($transfer);
        return $this->playable;
    }
    public function makePublic(ClipTransfer $transfer): void { $this->publicChanges++; }
    public function abandon(ClipTransfer $transfer): void { if ($this->onAbandon) ($this->onAbandon)($transfer); $this->deletions++; }
    public function cleanup(ClipTransfer $transfer): void { $this->cleanups++; }
}

$prefix = 'clipc_'.bin2hex(random_bytes(6)).'_';
$config = config('database.connections.'.config('database.default'));
if (($config['driver'] ?? null) !== 'mysql') throw new RuntimeException('Use the webcontainer MySQL fixture.');
config(['database.connections.clip_confirmation_fixture' => [...$config, 'prefix' => $prefix]]);
DB::setDefaultConnection('clip_confirmation_fixture');
if (Schema::getConnection()->getTablePrefix() !== $prefix) throw new RuntimeException('Unsafe fixture connection.');
$push = new ConfirmationPushFixture(); $app->instance(PushDeliveryService::class, $push);
$base = require __DIR__.'/../../../database/migrations/2026_11_07_000001_create_clip_transfers.php';
$lifecycle = require __DIR__.'/../../../database/migrations/2026_11_07_000002_add_clip_confirmation_lifecycle.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (! $ok) throw new RuntimeException($label); $checks++; };
$queue = new ClipTransferQueue();
$input = ['bytes' => 12051, 'sha256' => str_repeat('e', 64), 'title' => 'Home – original title', 'start' => 0, 'end' => 5, 'visibility' => 'private'];
$account = ['region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-clips', 'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-not-real'];
$gateway = new ConfirmationGatewayFixture();
$processor = new ClipTransferProcessor($gateway);
$request = static function (int $id): Request {
    $request = Request::create('/api/clip-transfers', 'GET');
    $request->setUserResolver(fn () => User::findOrFail($id));
    return $request;
};
$due = static function (ClipTransfer $job) use ($processor): void {
    $job->update(['next_check_at' => now()]);
    if (! $processor->run($job->uuid)) throw new RuntimeException('Expected a due workflow phase.');
    $job->refresh();
};
$reset = static function (): void {
    if (! str_starts_with(DB::connection()->getTablePrefix(), 'clipc_')) throw new RuntimeException('Unsafe fixture cleanup.');
    DB::table('user_notifications')->delete(); DB::table('clip_transfers')->delete();
};
try {
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->json('settings')->nullable(); });
    Schema::create('videos', fn (Blueprint $t) => $t->id());
    Schema::create('user_notifications', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('user_id');
        foreach (['actor_id', 'video_id', 'comment_id', 'channel_id', 'community_post_id'] as $key) $t->unsignedBigInteger($key)->nullable();
        $t->string('type'); $t->json('data')->nullable(); $t->timestamp('read_at')->nullable(); $t->timestamps();
    });
    DB::table('users')->insert([['id' => 1, 'settings' => '{}'], ['id' => 2, 'settings' => '{}']]);
    DB::table('videos')->insert(['id' => 1]);
    $base->up(); $lifecycle->up(); $queue->configureOracle($account);
    Carbon::setTestNow('2026-11-07 12:00:00');
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $queue->uploaded(1, $job->uuid);
    foreach (['registering', 'publishing', 'processing', 'awaiting_confirmation'] as $phase) {
        $due($job); $check($job->phase === $phase, 'Persist every phase before advancing.');
    }
    $check($gateway->creates === 1 && $gateway->registrations === 1 && $gateway->seals === 1, 'One browser upload must produce one provider publication.');
    $check($job->publisher_channel_id === str_repeat('a', 40), 'Keep the selected publishing channel stable through the wait.');
    $check(UserNotification::count() === 0 && ClipTransfer::available()->count() === 0, 'An accepted provider entry must not be displayed or notified.');
    Carbon::setTestNow('2026-11-07 14:00:00');
    $due($job);
    $check($job->phase === 'awaiting_confirmation' && $job->available_at === null, 'Two hours of provider delay remain pending rather than a ten-minute failure.');
    $check($gateway->deletions === 0 && $gateway->cleanups === 0, 'Waiting for confirmation must not remove the Odysee upload or staging copy.');
    $controller = new ClipTransferController();
    $payload = $controller->show($request(1), $job->uuid)->getData(true)['transfer'];
    $check($payload['state'] === 'awaiting_confirmation' && ! $payload['can_watch'] && ! isset($payload['embed_url']), 'The owner sees the wait, with no premature watch link.');
    $check(! str_contains(json_encode($payload), 'uploads-v4') && ! str_contains(json_encode($payload), $input['sha256']), 'Status responses must not leak transport capabilities.');
    try { $controller->playback($request(1), $job->uuid); throw new RuntimeException('Pending playback accepted.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $check($e->getStatusCode() === 409, 'Block playback until confirmation.'); }
    try { $controller->show($request(2), $job->uuid); throw new RuntimeException('Foreign status accepted.'); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException) { $check(true, 'Other users cannot inspect private transfer state.'); }
    $check($controller->index($request(2))->getData(true)['transfers'] === [], 'Lists are owner-bound too.');
    $gateway->playable = true; $due($job);
    $check($job->phase === 'ready' && $job->available_at !== null && ClipTransfer::available()->count() === 1, 'Confirmed playable media becomes available atomically.');
    $check(UserNotification::count() === 1 && $push->sent === 1, 'Create one notification after the availability transaction commits.');
    $denied = new class extends ClipTransferController {
        protected function authorizeSource(Request $request, ClipTransfer $row): void { abort(404); }
        protected function publisher(#[\SensitiveParameter] array $settings): App\Support\Clips\OdyseePublisher { throw new RuntimeException('Provider capability requested before source authorization.'); }
    };
    try { $denied->playback($request(1), $job->uuid); throw new RuntimeException('Source-denied playback accepted.'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $check($e->getStatusCode() === 404, 'Source authorization must run before issuing Odysee capabilities for ready clips.'); }
    $notification = UserNotification::firstOrFail();
    $check($notification->type === 'clip_ready' && (int) $notification->user_id === 1 && $notification->data['clip_id'] === $job->uuid, 'The notification belongs to the uploader and correct clip.');
    $webNotice = (new App\Http\Resources\NotificationResource($notification))->resolve($request(1));
    $nativeNotice = (new App\Http\Resources\Api\V1\NotificationResource($notification))->resolve($request(1));
    $check($webNotice['actor'] === 'Your clip is ready to watch.' && $webNotice['detail'] === $input['title'] && $webNotice['to'] === '/clip-uploads?clip='.$job->uuid, 'Web notification uses a complete message, literal author title and working owner route.');
    $check($nativeNotice['action'] === ['type' => 'clip_transfer', 'id' => $job->uuid] && $nativeNotice['body'] === $input['title'], 'The versioned API identifies the same clip.');
    $due($job); $check(UserNotification::count() === 1 && $push->sent === 1, 'Cleanup and repeated polls cannot send another ready notification.');
    $check($job->reservation_released_at !== null && $gateway->cleanups === 1, 'After grant expiry, confirmed cleanup can release the reservation.');
    $reset(); Carbon::setTestNow('2026-11-07 15:00:00');
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), [...$input, 'visibility' => 'public']);
    $job->update(['phase' => 'awaiting_confirmation', 'provider_state' => ['claim' => str_repeat('d', 40)]]);
    $due($job); $check($job->phase === 'releasing' && UserNotification::count() === 0, 'A public clip still waits for synchronized provider visibility.');
    $gateway->playable = false; $due($job);
    $check($job->phase === 'releasing' && ClipTransfer::available()->count() === 0, 'A visibility change does not imply availability.');
    $gateway->playable = true; $due($job);
    $check($job->phase === 'ready' && UserNotification::count() === 1, 'Notify only after the public result is confirmed.');
    $due($job);
    $check($job->reservation_released_at === null, 'Even successful cleanup retains capacity while its browser grant could be replayed.');
    Carbon::setTestNow('2026-11-07 15:21:00'); $due($job);
    $check($job->reservation_released_at !== null, 'Sweep again after the in-flight window before releasing storage.');
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'awaiting_confirmation']);
    $gateway->onAvailable = static fn ($row) => ClipTransfer::whereKey($row->id)->update(['cancel_requested' => true]);
    $due($job); $check($job->phase !== 'ready' && UserNotification::count() === 0, 'Cancellation committed during the provider request wins over readiness.');
    $gateway->onAvailable = null; $due($job);
    $check($job->phase === 'cancelled', 'Only explicit cancellation abandons this job.');
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'processing', 'provider_state' => ['query' => str_repeat('c', 32).'1794074400123'], 'cancel_requested' => true]);
    $gateway->onAbandon = static fn () => throw new OdyseePublishingFailure('cancel', 'pending');
    Carbon::setTestNow(now()->addMinutes(21));
    $before = $gateway->cleanups; $due($job);
    $check($job->phase === 'cancelling' && $job->reservation_released_at !== null && $gateway->cleanups === $before + 1, 'Pending provider cancellation independently clears expired staging and releases capacity.');
    $check(UserNotification::count() === 0 && ClipTransfer::available()->count() === 0, 'A late provider claim cannot make canceled work available.');
    $gateway->onAbandon = null; $before = $gateway->deletions; $due($job);
    $check($job->phase === 'cancelled' && $gateway->deletions === $before + 1, 'Keep reconciling until the late own provider claim can be removed.');
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'registering']);
    $gateway->onRegister = static fn () => throw new OdyseeImportFailure('registration');
    $before = $gateway->registrations; $due($job); $due($job);
    $check($gateway->registrations === $before + 1 && $job->last_error === 'registration_outcome_uncertain', 'A lost registration response cannot create duplicate uploads.');
    $gateway->onRegister = null;
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'processing', 'lease_token' => 'fixture-other-lease', 'lease_until' => now()->addMinute()]);
    $check(! $processor->run($job->uuid), 'A second worker cannot enter a leased phase.');
    $job->update(['lease_until' => now()->subSecond()]); $due($job);
    $check($job->phase === 'awaiting_confirmation', 'An expired lease resumes persisted work without another publication.');
    $reset();
    DB::table('users')->where('id', 1)->update(['settings' => json_encode(['preferences' => ['uploads' => false]])]);
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'awaiting_confirmation']); $due($job);
    $check($job->phase === 'ready' && UserNotification::count() === 0 && $job->notification_handled_at !== null, 'Respect disabled upload notifications without replaying suppressed events.');
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'awaiting_confirmation']);
    $deletions = $gateway->deletions; $cleanups = $gateway->cleanups;
    $gateway->playable = false; Carbon::setTestNow(now()->addHours(25)); $due($job);
    $check($job->phase === 'awaiting_confirmation' && $job->available_at === null && UserNotification::count() === 0, 'Provider processing stays pending even after 24 hours; time alone is not rejection.');
    $check($gateway->deletions === $deletions && $gateway->cleanups === $cleanups && $job->reservation_released_at === null, 'Keep the provider entry and staging reservation while waiting for confirmation.');
    $gateway->playable = true; $due($job);
    $check($job->phase === 'ready', 'A late provider confirmation can still make the original job available.');
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'processing']);
    $gateway->onPublication = static fn () => throw new OdyseePublishingFailure('status', 'provider', 200);
    $due($job);
    $check($job->phase === 'failed' && UserNotification::count() === 0, 'An explicit provider rejection fails without notifying success.');
    $gateway->onPublication = null;
    Carbon::setTestNow(now()->addMinutes(21)); $due($job);
    $check($job->reservation_released_at !== null, 'Clean staging for explicitly failed work after its upload grant expires.');
    echo $checks." clip confirmation checks passed. Controlled provider delays/time, owner rights, notifications and cleanup; isolated MySQL fixtures only.\n";
} finally {
    Carbon::setTestNow();
    if (Schema::hasTable('clip_transfers')) {
        $reset();
        if (Schema::hasColumn('clip_transfers', 'available_at')) $lifecycle->down();
        $base->down();
    }
    Schema::dropIfExists('user_notifications'); Schema::dropIfExists('videos'); Schema::dropIfExists('users');
    DB::disconnect('clip_confirmation_fixture');
}
