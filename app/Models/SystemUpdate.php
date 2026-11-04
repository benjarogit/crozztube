<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemUpdate extends Model
{
    public const ACTIVE_STATUSES = [
        'queued',
        'preflight',
        'downloading',
        'verifying',
        'preparing',
        'backing_up',
        'installing_files',
        'installing_dependencies',
        'migrating_database',
        'rebuilding_frontend',
        'refreshing_caches',
        'health_check',
        'finalizing',
        'rolling_back',
    ];

    protected $fillable = [
        'uuid',
        'from_version',
        'to_version',
        'status',
        'progress',
        'current_step',
        'package_checksum',
        'backup_path',
        'release_metadata',
        'error_details',
        'rollback_error',
        'installed_by',
        'heartbeat_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $hidden = ['package_checksum', 'backup_path'];

    protected function casts(): array
    {
        return [
            'release_metadata' => 'array',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
