<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $primaryKey = 'reference';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'reference',
        'deal_id',
        'date_utc',
        'instrument',
        'transaction_type',
        'pl_chf',
        'note',
    ];
}
