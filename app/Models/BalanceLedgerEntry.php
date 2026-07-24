<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BalanceLedgerEntry extends Model
{
    protected $table = 'balance_ledger_entries';

    protected $fillable = [
        'user_id',
        'username',
        'field',
        'delta',
        'balance_before',
        'balance_after',
        'reason',
        'source',
        'actor_id',
        'ref_type',
        'ref_id',
        'meta',
    ];

    protected $casts = [
        'delta' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'meta' => 'array',
    ];
}
