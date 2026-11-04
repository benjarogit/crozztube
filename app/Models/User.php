<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'gender',
        'date_of_birth',
        'registration_ip',
        'registration_provider',
        'password',
        'avatar_path',
        'settings',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'registration_ip',
        'registration_provider',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }

    public function channel(): HasOne
    {
        return $this->hasOne(Channel::class);
    }

    public function scopePublicProfileEnabled(Builder $query): Builder
    {
        return $query->where(fn (Builder $profiles) => $profiles
            ->whereNull('settings->preferences->publicProfile')
            ->orWhere('settings->preferences->publicProfile', true));
    }

    public function hasPublicProfileEnabled(): bool
    {
        return (bool) data_get($this->settings, 'preferences.publicProfile', true);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function liveStreams(): HasMany
    {
        return $this->hasMany(LiveStream::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    public function subscribedChannels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'subscriptions')->withTimestamps();
    }

    public function activeBans(): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $bans = is_array($settings['bans'] ?? null) ? $settings['bans'] : [];

        return collect($bans)
            ->filter(function ($ban): bool {
                $expiresAt = $this->banExpiry($ban);

                return (bool) (is_array($ban) && ($ban['forever'] ?? false)) || ($expiresAt && $expiresAt->isFuture());
            })
            ->map(function ($ban): array {
                $expiresAt = $this->banExpiry($ban);

                return array_merge(is_array($ban) ? $ban : [], [
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'expires_label' => (is_array($ban) && ($ban['forever'] ?? false)) ? 'Forever' : $expiresAt?->format('M j, Y g:i A'),
                ]);
            })
            ->all();
    }

    public function isBannedFrom(string $scope): bool
    {
        $bans = $this->activeBans();

        return isset($bans['account']) || isset($bans[$scope]);
    }

    public function banMessage(string $scope): string
    {
        $labels = [
            'account' => 'Your account is locked',
            'comments' => 'You cannot post comments',
            'uploads' => 'You cannot upload videos',
            'live' => 'You cannot go live',
            'watch' => 'You cannot watch videos or shorts',
        ];
        $bans = $this->activeBans();
        $ban = $bans['account'] ?? ($bans[$scope] ?? null);
        $until = $ban['expires_label'] ?? null;

        return ($labels[isset($bans['account']) ? 'account' : $scope] ?? 'This action is restricted').($until ? ' until '.$until.'.' : '.');
    }

    private function banExpiry($ban): ?Carbon
    {
        if (! is_array($ban) || empty($ban['expires_at'])) {
            return null;
        }

        try {
            return Carbon::parse($ban['expires_at']);
        } catch (\Throwable) {
            return null;
        }
    }
}
