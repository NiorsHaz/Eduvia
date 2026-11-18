<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'created_at' => 'datetime',
    ];
}
