<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PlatformAppVersion extends Model
{
    protected $fillable = [
        'latest_version',
        'minimum_version',
        'supported',
        'update_url',
        'release_notes_url',
        'update_message',
    ];

    protected function casts(): array
    {
        return [
            'supported' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $forget = static function (self $setting): void {
            try {
                Cache::forget('playhub:client-version:'.$setting->platform);
                Cache::forget('playhub:client-versions');
            } catch (Throwable) {
                // The database update remains valid when an external cache is unavailable.
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function getRouteKeyName(): string
    {
        return 'platform';
    }
}
