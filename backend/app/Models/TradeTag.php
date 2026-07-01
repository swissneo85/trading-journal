<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeTag extends Model
{
    protected $table = 'trade_tags';

    protected $primaryKey = 'deal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'deal_id',
        'quelle',
        'notiz',
        'tagged_at',
    ];
}
