<?php

namespace App\Services\Api\V1;

use App\Models\LiveStream;
use App\Models\User;
use App\Support\LiveStreamingSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PublishingAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LiveStreamAccessService
{
    public function __construct(private readonly VideoAccessService $videos) {}

    public function directoryQuery(?User $viewer): Builder
    {
        $query = LiveStream::query()
            ->where('visibility', 'public')
            ->where(fn (Builder $streams) => $streams
                ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
                ->when($viewer, fn (Builder $streams) => $streams->orWhere('user_id', $viewer->id)))
            ->where(function (Builder $query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn (Builder $category) => $category->where('enabled', true));
            })
            ->where(function (Builder $query): void {
                $query->where('status', '!=', 'ended')
                    ->orWhere(function (Builder $ended): void {
                        $ended->where('status', 'ended')
                            ->whereNotNull('replay_video_id')
                            ->where(fn (Builder $expiry) => $expiry
                                ->whereNull('replay_expires_at')
                                ->orWhere('replay_expires_at', '>', now()))
                            ->whereHas('replayVideo', fn (Builder $video) => $this->videos->constrainPublic($video));
                    });
            });

        return $query->where(function (Builder $query) use ($viewer): void {
            $query->whereNull('stream_settings')
                ->orWhereNull('stream_settings->audience_mode')
                ->orWhere('stream_settings->audience_mode', 'everyone');

            if (! $viewer) {
                return;
            }

            $query->orWhere('stream_settings->audience_mode', 'signed_in')
                ->orWhere(function (Builder $query) use ($viewer): void {
                    $query->where('stream_settings->audience_mode', 'subscribers')
                        ->whereIn('channel_id', DB::table('subscriptions')
                            ->where('user_id', $viewer->id)
                            ->select('channel_id'));
                })
                ->orWhere(function (Builder $query) use ($viewer): void {
                    $query->where('stream_settings->audience_mode', 'invited')
                        ->whereIn('id', DB::table('live_stream_invites')
                            ->where('user_id', $viewer->id)
                            ->select('live_stream_id'));
                })
                ->orWhere('user_id', $viewer->id);
        });
    }

    public function canView(LiveStream $stream, ?User $viewer): bool
    {
        if (! \App\Support\UserBlocks::canSee($viewer, (int) $stream->user_id)) return false;

        if ($viewer && (int) $stream->user_id === (int) $viewer->id) {
            return true;
        }

        $stream->loadMissing('user');
        if (! $stream->user?->hasPublicProfileEnabled()) {
            return false;
        }

        $privileged = $viewer && (DB::table('live_stream_moderators')->where('live_stream_id', $stream->id)->where('user_id', $viewer->id)->exists()
            || DB::table('live_stream_invites')->where('live_stream_id', $stream->id)->where('user_id', $viewer->id)->whereIn('role', ['guest', 'cohost'])->exists());

        if ($stream->visibility === 'private' && ! $privileged) {
            return false;
        }

        if ($stream->status === 'ended') {
            if (! $stream->replay_video_id || $stream->replay_expires_at?->isPast()) {
                return false;
            }

            $replay = $stream->relationLoaded('replayVideo')
                ? $stream->replayVideo
                : $stream->replayVideo()->first();
            if (! $replay || ! $this->videos->canView($replay, $viewer, true)) {
                return false;
            }
        } elseif (! in_array($stream->status, ['scheduled', 'waiting-for-source', 'live'], true)) {
            return false;
        }

        $audience = (string) data_get($stream->stream_settings, 'audience_mode', 'everyone');
        if ($privileged) {
            return true;
        }

        return match ($audience) {
            'everyone', '' => true,
            'signed_in' => $viewer !== null,
            'subscribers' => $viewer && DB::table('subscriptions')
                ->where('user_id', $viewer->id)
                ->where('channel_id', $stream->channel_id)
                ->exists(),
            'invited' => $viewer && DB::table('live_stream_invites')
                ->where('user_id', $viewer->id)
                ->where('live_stream_id', $stream->id)
                ->exists(),
            default => false,
        };
    }

    public function assertViewer(LiveStream $stream, ?User $viewer): void
    {
        $settings = LiveStreamingSettings::settings();
        abort_unless((bool) ($settings['enabled'] ?? false) && (bool) data_get(PlatformFeatureSettings::settings(), 'liveStreaming.enabled', true), 403, 'Live streaming is disabled.');
        abort_unless($this->canView($stream, $viewer), 404, 'Live stream not found.');
        if ($viewer?->isBannedFrom('watch')) {
            abort(403, $viewer->banMessage('watch'));
        }

        if ($viewer) {
            $restricted = DB::table('live_stream_restrictions')
                ->where('live_stream_id', $stream->id)
                ->where('user_id', $viewer->id)
                // The web moderation endpoint writes `stream`; retain legacy `ban` rows.
                ->whereIn('type', ['stream', 'ban'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
            abort_if($restricted, 403, 'You cannot join this live stream.');
        }
    }

    public function assertPublisher(LiveStream $stream, User $viewer): void
    {
        abort_unless((int) $stream->user_id === (int) $viewer->id, 403, 'Only the stream owner can publish.');
        $this->assertViewer($stream, $viewer);
        PublishingAccess::authorizeLive($viewer);
        abort_if($viewer->isBannedFrom('live'), 403, $viewer->banMessage('live'));
        abort_unless(in_array($stream->status, ['waiting-for-source', 'live'], true), 409, 'The live stream is not ready for a publisher connection.');
    }
}
