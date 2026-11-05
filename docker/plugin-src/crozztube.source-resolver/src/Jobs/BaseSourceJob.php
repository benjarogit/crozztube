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
 *
 * Single-Flight: nur EIN Lauf pro Video (Cache-Lock + DB-Zustand `refreshing`),
 * parallele Anfragen stoessen keinen zweiten yt-dlp-Lauf an.
 *
 * `$force` ueberspringt die "ist noch frisch"-Pruefung, damit ablaufende URLs
 * und manuelle Refresh-Anfragen auch bei gueltigem Zustand neu aufgeloest
 * werden koennen.
 */
abstract class BaseSourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const LOCK_TTL = 600;

    public const MAX_ATTEMPTS = 4;

    /** Wartezeit, bevor derselbe Video-Refresh erneut eingereiht wird. */
    public const REQUEST_DEBOUNCE = 30;

    /**
     * yt-dlp-Fehler sind oft transient (CDN-Hiccup, kurzer DNS-Fehler). Der
     * Queue-Worker wiederholt den Job mit Backoff; danach bleibt der Zustand
     * sauber auf `error` stehen, damit die UI den Mirror anbietet.
     */
    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(
        public readonly int $videoId,
        public readonly bool $force = false,
        /**
         * Unix-Zeitpunkt, zu dem der Refresh angefordert wurde. Damit koennen
         * mehrere gleichzeitig wartende Nutzer denselben Ablauf ausloesen, ohne
         * mehrere yt-dlp-Laeufe zu erzeugen: Jobs, die vor dem erfolgreichen
         * Abschluss eines anderen Jobs eingereiht wurden, steigen aus.
         */
        public readonly ?int $requestedAt = null,
    ) {
    }

    public function handle(): void
    {
        $resolver = Resolver::available() ? app(Resolver::class) : null;
        if (! $resolver) {
            return;
        }

        $lock = Cache::lock('source-resolver:video:'.$this->videoId, self::LOCK_TTL);
        if (! $lock->get()) {
            // Ein anderer Worker loest dieses Video bereits auf.
            return;
        }

        try {
            if (! \App\Models\Video::whereKey($this->videoId)->exists()) return;
            $row = CrozztubeImportSource::query()->firstOrCreate(
                ['video_id' => $this->videoId],
                ['kind' => 'pending', 'state' => 'refreshing', 'media_url' => '']
            );

            // Nichts zu tun, solange die vorhandene Quelle gueltig ist. Das gilt
            // auch fuer den Auto-Refresh: der prueft vorher expires_at.
            if (! $this->force && $row->isFresh()) {
                return;
            }

            // Single-Flight ueber mehrere Nutzer: Wurde die Quelle NACH dem
            // Eintreffen dieser Anfrage bereits erneuert, ist der Refresh schon
            // erledigt - auch wenn dieser Job `force` traegt.
            if ($this->force
                && $this->requestedAt !== null
                && $row->isFresh()
                && $row->resolved_at !== null
                && $row->resolved_at->getTimestamp() >= $this->requestedAt) {
                return;
            }

            $row->update([
                'state' => 'refreshing',
                'refresh_started_at' => now(),
                'refresh_attempts' => $row->refresh_attempts + 1,
            ]);

            $resolver->resolveForVideo($row);
        } catch (Throwable $exception) {
            $category = $exception instanceof \App\Support\ResolverFailure ? $exception->category : 'unknown';
            CrozztubeImportSource::query()->where('video_id', $this->videoId)->update([
                'state' => 'error',
                'error_message' => 'Video source resolution failed ('.$category.').',
                'error_category' => $category === 'unknown' && $this->attempts() >= 2 ? 'unknown_exhausted' : $category,
                'refresh_started_at' => null,
            ]);

            Log::warning('CrzSourceResolver: Aufloesung fehlgeschlagen', [
                'video_id' => $this->videoId,
                'category' => $category,
            ]);

            if ($category === 'permanent' || ($category === 'unknown' && $this->attempts() >= 2)) {
                $this->fail($exception);
                return;
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function backoff(): array
    {
        return [20, 90, 240];
    }

    /**
     * Letzter Fehlversuch: Zustand bleibt sichtbar `error`, der Lock ist frei
     * und wartende Clients koennen den Mirror nutzen oder erneut anfordern.
     */
    public function failed(?Throwable $exception): void
    {
        CrozztubeImportSource::query()->where('video_id', $this->videoId)->update([
            'state' => 'error',
            'error_message' => 'Video source resolution failed.',
            'error_category' => $exception instanceof \App\Support\ResolverFailure && $exception->category === 'permanent' ? 'permanent' : ($exception instanceof \App\Support\ResolverFailure && $exception->category === 'transient' ? 'transient' : 'unknown_exhausted'),
            'refresh_started_at' => null,
        ]);
    }

    /**
     * Sicherer Trigger von ueberall: dedupliziert per Cache und markiert eine
     * abgelaufene Quelle als `stale`, damit wartende Clients das sehen.
     */
    public static function request(int $videoId, bool $force = false, int $delaySeconds = 0, ?string $queue = null, bool $allowFailed = false): bool
    {
        $row = CrozztubeImportSource::query()->where('video_id', $videoId)->first();

        if ($row) {
            if (! $allowFailed && in_array($row->error_category, ['permanent', 'unknown_exhausted'], true)) return false;
            if ($allowFailed) $row->forceFill(['error_category' => null])->save();
            if (! $force && $row->isFresh()) {
                return false;
            }

            $running = $row->state === 'refreshing'
                && $row->refresh_started_at?->gt(now()->subSeconds(self::LOCK_TTL - 60));

            if ($running) {
                return false;
            }
        }

        // Debounce only opportunistic refreshes. An explicit request (user
        // pressed Retry, expiry job, CLI) must never be swallowed; concurrent
        // duplicates are cheap because the job itself holds a lock.
        if (! $force && ! Cache::add('source-resolver:req:'.$videoId, 1, self::REQUEST_DEBOUNCE)) {
            return false;
        }

        // Abgelaufene, aber noch vorhandene Quelle: Nutzer sollen "wird
        // erneuert" sehen statt eines kaputten Players.
        if ($row && ! $row->isFresh() && $row->hasDirectSource() && $row->state !== 'refreshing') {
            $row->update(['state' => 'stale']);
        }

        $job = static::dispatch($videoId, $force, now()->getTimestamp());

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        return true;
    }

    /**
     * Setzt haengende `refreshing`-Zustaende zurueck (Worker-Neustart, Timeout).
     *
     * Wichtig: Der Vergleich nutzt einen von PHP erzeugten Zeitstempel, nicht
     * SQL NOW(). Die MySQL-Session laeuft in UTC, die App in Europe/Berlin;
     * NOW() waere hier eine Stunde daneben.
     */
    public static function recoverStale(int $videoId): void
    {
        CrozztubeImportSource::query()
            ->where('video_id', $videoId)
            ->where('state', 'refreshing')
            ->where('refresh_started_at', '<', now()->subSeconds(self::LOCK_TTL)->format('Y-m-d H:i:s'))
            ->update([
                'state' => 'stale',
                'refresh_started_at' => null,
                'error_message' => 'Refresh abgebrochen (Timeout).',
            ]);
    }
}
