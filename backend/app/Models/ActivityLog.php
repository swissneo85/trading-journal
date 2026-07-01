<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public $timestamps = false;

    protected $fillable = [
        'type',
        'message',
        'details',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now()->toIso8601String();
        });
    }

    public static function log(string $type, string $message, ?array $details = null): self
    {
        return static::create([
            'type' => $type,
            'message' => $message,
            'details' => $details ? json_encode($details) : null,
        ]);
    }
}
