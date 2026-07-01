<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';

    public $timestamps = false;

    // Composite primary key (deal_id, date_utc) is not natively supported by
    // Eloquent, so writes must go through Activity::upsert() rather than
    // updateOrCreate()/save() which rely on a single-column key.
    public $incrementing = false;

    protected $fillable = [
        'deal_id',
        'date_utc',
        'epic',
        'instrument',
        'direction',
        'size',
        'level',
        'open_price',
        'source',
    ];
}
