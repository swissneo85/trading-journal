<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $table = 'sources';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'archived',
        'created_at',
    ];

    protected $casts = [
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $source) {
            $source->created_at ??= now()->toIso8601String();
        });
    }
}
