<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'committed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(ImportBatchError::class, 'batch_id');
    }
}
