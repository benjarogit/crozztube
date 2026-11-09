<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    // Real database configuration must never appear in test output.
    $diagnostic = $error instanceof Illuminate\Database\QueryException ? 'SQL error '.(int) ($error->errorInfo[1] ?? 0)
        : ($error instanceof RuntimeException ? $error->getMessage() : get_class($error));
    fwrite(STDERR, 'Clip queue checks failed: '.$diagnostic."\n");
    exit(1);
});

use App\Models\ClipStagingAccount;
use App\Models\ClipTransfer;
use App\Support\Clips\ClipQueueRejected;
use App\Support\Clips\ClipTransferQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$child = ($argv[1] ?? '') === '--child';
$prefix = $child ? ($argv[2] ?? '') : 'clipq_'.bin2hex(random_bytes(6)).'_';
if (! preg_match('/^clipq_[a-f0-9]{12}_$/D', $prefix)) {
    throw new RuntimeException('Invalid isolated fixture prefix.');
}
$connection = config('database.connections.'.config('database.default'));
if (($connection['driver'] ?? null) !== 'mysql') {
    throw new RuntimeException('Run this queue check in the webcontainer against MySQL for real row-lock coverage.');
}
config(['database.connections.clip_queue_fixture' => [...$connection, 'prefix' => $prefix]]);
DB::setDefaultConnection('clip_queue_fixture');
if (Schema::getConnection()->getTablePrefix() !== $prefix) {
    throw new RuntimeException('Schema fixtures must use the isolated prefix.');
}
$queue = new ClipTransferQueue();
$input = ['bytes' => 67108864, 'sha256' => str_repeat('b', 64), 'title' => 'Fixture clip', 'start' => 0, 'end' => 5, 'visibility' => 'private'];
if ($child) {
    $owner = (int) ($argv[3] ?? 0);
    if (! in_array($owner, [7, 8], true)) {
        throw new RuntimeException('Invalid fixture owner.');
    }
    $transfer = $queue->enqueue($owner, 1, (string) Str::uuid(), $input);
    echo $transfer->phase;
    exit(0);
}

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $reason) use ($check): void {
    try {
        $call();
        throw new RuntimeException('Unexpected queue acceptance.');
    } catch (ClipQueueRejected $error) {
        $check($error->reason === $reason, 'Wrong queue rejection reason.');
    }
};
$settings = ['region' => 'eu-frankfurt-1', 'namespace' => 'fixture', 'bucket' => 'fixture-clips',
    'access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret-not-real'];
$migration = require __DIR__.'/../../../database/migrations/2026_11_07_000001_create_clip_transfers.php';
$reset = static function (): void {
    if (! str_starts_with(DB::connection()->getTablePrefix(), 'clipq_')) {
        throw new RuntimeException('Refusing to touch non-fixture tables.');
    }
    DB::table('clip_transfers')->delete();
};
try {
    Schema::create('users', fn (Blueprint $table) => $table->id());
    Schema::create('videos', fn (Blueprint $table) => $table->id());
    DB::table('users')->insert(array_map(static fn ($id) => ['id' => $id], range(1, 8)));
    DB::table('videos')->insert(['id' => 1]);
    $migration->up();
    $accountId = $queue->configureOracle($settings);
    $account = ClipStagingAccount::findOrFail($accountId);
    $check($account->connection['secret_access_key'] === $settings['secret_access_key'], 'Workers must be able to decrypt the bucket-bound connection.');
    $check(! str_contains(DB::table('clip_staging_accounts')->value('connection'), $settings['secret_access_key']), 'Credentials must be encrypted at rest.');
    $check(! str_contains($account->toJson(), $settings['secret_access_key']) && ! array_key_exists('connection', $account->toArray()), 'Account JSON must never expose its connection.');
    $key = (string) Str::uuid();
    $first = $queue->enqueue(1, 1, $key, $input);
    $check($first->phase === 'uploading' && $first->reserved_bytes === 134217728, 'Admission reserves incoming plus handoff atomically.');
    $check($first->account_id === $accountId && $first->grant_until->diffInSeconds(now(), true) <= 300, 'The job remains assigned to one account with a short grant.');
    $check($first->cleanup_not_before->timestamp - $first->grant_until->timestamp === 900, 'Reservation outlives the upload capability and in-flight window.');
    $check($queue->enqueue(1, 1, $key, $input)->uuid === $first->uuid && ClipTransfer::count() === 1, 'Repeated clicks reuse one job and one daily allowance.');
    $reject(static fn () => $queue->enqueue(1, 1, $key, [...$input, 'title' => 'Changed']), 'idempotency_conflict');
    $second = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $check($second->phase === 'queued', 'One user may hold one active and one waiting job.');
    $reject(static fn () => $queue->enqueue(1, 1, (string) Str::uuid(), $input), 'waiting_limit');
    $third = $queue->enqueue(2, 1, (string) Str::uuid(), $input);
    $check($queue->status(1, $second->uuid)['position'] === 1 && $queue->status(2, $third->uuid)['position'] === 2, 'FIFO positions reflect the shared queue.');
    $reject(static fn () => $queue->status(2, $first->uuid), 'not_found');
    $reject(static fn () => $queue->cancel(2, $first->uuid), 'not_found');
    $queue->uploaded(1, $first->uuid);
    $check($queue->uploaded(1, $first->uuid)->phase === 'sealing', 'Duplicate upload notifications must not reset a later phase.');
    $queue->cancel(1, $first->uuid);
    $check($first->fresh()->reserved_bytes === 134217728 && $first->fresh()->reservation_released_at === null, 'Cancellation cannot free a still-writable reservation.');
    $reject(static fn () => $queue->uploaded(1, $first->uuid), 'cancelled');
    $queue->cancel(1, $second->uuid);
    $check($second->fresh()->phase === 'cancelled' && $second->fresh()->reserved_bytes === 0, 'Cancel a waiting job without allocating storage.');
    $status = $queue->status(1, $first->uuid);
    $check(array_keys($status) === ['id', 'state', 'position', 'cancel_requested'], 'Owner status exposes no credentials, checksums or internal provider references.');
    $reset();
    Carbon::setTestNow('2026-11-07 12:00:00');
    for ($n = 0; $n < 5; $n++) {
        $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
        $queue->cancel(1, $job->uuid);
        // Simulate the later worker's terminal cleanup state in these isolated tables.
        $job->update(['phase' => 'cancelled']);
    }
    $reject(static fn () => $queue->enqueue(1, 1, (string) Str::uuid(), $input), 'daily_limit');
    Carbon::setTestNow('2026-11-08 00:00:01');
    $check($queue->enqueue(1, 1, (string) Str::uuid(), $input)->id !== null, 'Daily admission resets at the next configured application calendar day.');
    Carbon::setTestNow();
    $reset();
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    Carbon::setTestNow(now()->addMinutes(6));
    $reject(static fn () => $queue->uploaded(1, $job->uuid), 'upload_expired');
    Carbon::setTestNow();
    $otherId = $queue->configureOracle([...$settings, 'bucket' => 'changed-fixture-bucket']);
    $check($job->fresh()->account_id === $accountId && ! ClipStagingAccount::find($accountId)->enabled && $otherId !== $accountId, 'Changing a bucket cannot move an existing upload to another account.');
    $check(ClipStagingAccount::find($accountId)->connection['bucket'] === 'fixture-clips', 'Keep the old encrypted connection for pending cleanup.');
    $reset();
    $queue->configureOracle($settings);
    ClipStagingAccount::find($accountId)->update(['suspended_until' => now()->addHour()]);
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $check($job->phase === 'queued' && $job->reserved_bytes === 0, 'Unhealthy accounts keep jobs queued without granting uploads.');
    ClipStagingAccount::find($accountId)->update(['suspended_until' => null]);
    $queue->promote();
    $check($job->fresh()->phase === 'uploading', 'Recovery resumes the existing queued job.');
    $reset();
    // Occupancy from completed/cancelled work is retained until cleanup confirmation.
    $job = $queue->enqueue(1, 1, (string) Str::uuid(), $input);
    $job->update(['phase' => 'cancelled', 'reserved_bytes' => 950000000]);
    $job2 = $queue->enqueue(2, 1, (string) Str::uuid(), $input);
    $check($job2->phase === 'queued', 'A free processing slot is not the same as free storage capacity.');
    $job->update(['reservation_released_at' => now()]);
    $queue->promote();
    $check($job2->fresh()->phase === 'uploading', 'Only confirmed released capacity permits another admission.');
    $reset();
    // Two real independent MySQL connections compete for the same account slot.
    $children = [];
    foreach ([7, 8] as $owner) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $prefix, (string) $owner],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Concurrency worker could not start.');
        }
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process) === 0 && $err === '' && in_array($out, ['queued', 'uploading'], true), 'The independent queue writer must finish without leaking diagnostics.');
    }
    $check(ClipTransfer::where('phase', 'uploading')->count() === 1 && ClipTransfer::where('phase', 'queued')->count() === 1, 'Concurrent admissions cannot claim one Oracle slot twice.');
    $check((int) ClipTransfer::sum('reserved_bytes') === 134217728, 'Concurrent admission cannot over-reserve storage.');
    $reset();
    foreach (['pool-a', 'pool-b', 'pool-c'] as $id) {
        ClipStagingAccount::create(['id' => $id, 'provider' => 'oci', 'connection' => $settings, 'enabled' => true, 'capacity_bytes' => 1000000000]);
    }
    $limited = new ClipTransferQueue(2);
    foreach ([1, 2, 3] as $owner) {
        $limited->enqueue($owner, 1, (string) Str::uuid(), $input);
    }
    $check(ClipTransfer::where('phase', 'uploading')->count() === 2 && ClipTransfer::where('phase', 'queued')->count() === 1, 'The global limit applies even when more healthy accounts have room.');
    $check(ClipTransfer::where('phase', 'uploading')->distinct()->count('account_id') === 2, 'Each account has at most one active transfer.');
    try {
        $migration->down();
        throw new RuntimeException('Unsafe rollback accepted.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'reservations must be cleaned'), 'Rollback must not discard active cleanup obligations.');
    }
    echo $checks." clip queue checks passed, including independent MySQL writers. Isolated prefixed tables only; no remote uploads.\n";
} finally {
    Carbon::setTestNow();
    if (Schema::hasTable('clip_transfers')) {
        $reset();
        $migration->down();
    }
    Schema::dropIfExists('clip_staging_accounts');
    Schema::dropIfExists('clip_transfer_locks');
    Schema::dropIfExists('videos');
    Schema::dropIfExists('users');
    DB::disconnect('clip_queue_fixture');
}
