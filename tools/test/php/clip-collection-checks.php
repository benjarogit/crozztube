<?php

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClipCollection;
use App\Models\ClipTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$check(Schema::hasTable('clip_collections') && Schema::hasTable('clip_collection_clip'),
    'Clip collection migrations must be installed.');
$userId = (int) DB::table('users')->orderBy('id')->value('id');
$videoId = (int) DB::table('videos')->orderBy('id')->value('id');
$check($userId > 0 && $videoId > 0, 'Clip collection checks require an existing user and video.');

DB::beginTransaction();
try {
    $collection = ClipCollection::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $userId,
        'title' => 'Fixture collection',
        'visibility' => 'private',
    ]);
    $clip = ClipTransfer::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $userId,
        'video_id' => $videoId,
        'request_key' => (string) Str::uuid(),
        'request_hash' => str_repeat('1', 64),
        'phase' => 'ready',
        'bytes' => 1024,
        'sha256' => str_repeat('2', 64),
        'metadata' => ['title' => 'Fixture clip', 'start' => 2.0, 'end' => 7.0, 'visibility' => 'private'],
        'deadline_at' => now()->addHour(),
        'available_at' => now(),
        'provider_state' => ['claim' => str_repeat('a', 40)],
    ]);
    $collection->clips()->attach($clip->id, ['sort_order' => 1]);
    $check($collection->clips()->whereKey($clip->id)->exists(), 'A clip must be attachable to its owner collection.');
    $check($clip->collections()->whereKey($collection->id)->exists(), 'Clip collection membership must be bidirectional.');
    $check(! str_contains($clip->toJson(), str_repeat('a', 40)), 'Provider state must stay hidden in catalog JSON.');
    $collection->delete();
    $check(! DB::table('clip_collection_clip')->where('clip_transfer_id', $clip->id)->exists(),
        'Deleting a collection must cascade its memberships without deleting the clip.');
    $check(ClipTransfer::query()->whereKey($clip->id)->exists(), 'Deleting a collection must preserve its clips.');
} finally {
    DB::rollBack();
}

echo "Clip collection checks passed: {$checks}\n";
