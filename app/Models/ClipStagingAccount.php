<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ClipStagingAccount extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['connection'];

    protected $casts = ['connection' => 'encrypted:array', 'enabled' => 'boolean',
        'suspended_until' => 'immutable_datetime', 'capacity_bytes' => 'integer'];
}
