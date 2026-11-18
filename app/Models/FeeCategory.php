<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeCategory extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_refundable' => 'boolean',
        'is_active' => 'boolean',
    ];
}
