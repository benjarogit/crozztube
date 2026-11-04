<?php

namespace CrzSourceResolver\Jobs;

use CrzSourceResolver\Models\CrozztubeImportSource;
use CrzSourceResolver\Resolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loest/aktualisiert die Direkt-URL fuer ein importiertes Video.
 * Single-Flight: Nur EIN Aufruf pro Video (Cache-Lock + DB-Sperre `refreshing`),
 * parallele Anforderungen greifen den laufenden RefreshNICHT nochmal an.
 */
abstract class BaseSourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const LOCK_TTL = 600;

    public const MAX_ATTEMPTS = 4;

    public function __construct(public readonly int $videoId)
    {
    }

    public function handle(): void
    {
        $resolver = Resolver::available() ? app(Resolver::class) : null;
        if (! $resolver) {
            return;
        }

        $lockKey = 'source-resolver:video:'.$this->videoId;
        $lock = Cache::lock($lockKey, self::LOCK_TTL);

        if (! $lock->get()) {
            return; // laeuft bereits => nichts tun (Single-Flight)
        }

        try {
            $row = CrozztubeImportSource::query()->firstOrCreate(
                ['video_id' => $this->videoId],
                ['kind' => 'pending', 'state' => 'refreshing', 'media_url' => '']
            );

            if ($row->state === 'fresh') {
                return;
            }

            $row->update([
                'state' => 'refreshing',
                'refresh_started_at' => now(),
                'refresh_attempts' => $row->refresh_attempts + 1,
            ]);

            $resolver->resolveForVideo($row);
        } catch (Throwable $exception) {
            CrozztubeImportSource::query()->where('video_id', $this->videoId)->update([
                'state' => 'error',
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'refresh_started_at' => null,
            ]);

            Log::warning('CrzSourceResolver: Auflösung fehlgeschlagen', [
                'video_id' => $this->videoId,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Dient als Trigger von überall sicheres Anstoßen eines Refreshs:
     * "refreshing"-Datensatz schützt vor doppeltem Dispatch.
     */
    public static function request(int $videoId): void
    {
        $row = CrozztubeImportSource::query()->where('video_id', $videoId)->first();

        if (! $row) {
            // Stampede-Schutz: maximal 1 Dispatch pro 30s pro Video
            if (! Cache::add('source-resolver:req:'.$videoId, 1, 30)) {
                return;
            }

            static::dispatch($videoId);

            return;
        }

        if ($row->state === 'fresh'
            || ($row->state === 'refreshing'
                && $row->refresh_started_at?->gt(now()->subSeconds(self::LOCK_TTL - 60)))) {
            return;
        }

        if (! Cache::add('source-resolver:req:'.$videoId, 1, 30)) {
            return;
        }

        static::dispatch($videoId);
    }
}
