<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigEntry extends Model
{
    protected $table = 'config';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
    ];
}
