<?php

namespace App\Console\Commands;

use App\Models\UploadSession;
use App\Support\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCreatorUploads extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Remove expired upload chunks and completed recovery records after seven days';

    public function handle(): int
    {
        UploadSession::query()->where('created_at', '<', now()->subDays(7))
            ->whereNotIn('status', ['assembling', 'processing'])->chunkById(100, function ($sessions): void {
                foreach ($sessions as $session) {
                    foreach (array_unique(['local', 'public', MediaStorage::diskName()]) as $disk) {
                        Storage::disk($disk)->deleteDirectory('videos/chunks/'.$session->uuid);
                    }
                    $session->delete();
                }
            });

        return self::SUCCESS;
    }
}
