<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeGenerationRun extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'scope' => 'array',
        'created_at' => 'datetime',
    ];
}
