<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingDefinition extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'allowed_values' => 'array',
        'default_value' => 'array',
        'allowed_scopes' => 'array',
    ];
}
