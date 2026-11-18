<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuleMetric extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['params_schema' => 'array'];
}
