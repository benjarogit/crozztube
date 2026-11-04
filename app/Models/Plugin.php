<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    public $incrementing = false;

    protected $table = 'playhub_plugins';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'version',
        'description',
        'author',
        'manifest',
        'settings',
        'checksum',
        'enabled',
        'last_error',
        'installed_by',
        'updated_by',
        'installed_at',
        'enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'settings' => 'encrypted:array',
            'enabled' => 'boolean',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
        ];
    }
}
