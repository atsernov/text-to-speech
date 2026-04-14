<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceSample extends Model
{
    protected $fillable = [
        'name',
        'languages',
        'sample_filename',
        'is_active',
        'last_checked_at',
    ];

    protected $casts = [
        'languages' => 'array',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];
}
