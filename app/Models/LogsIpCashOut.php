<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogsIpCashOut extends Model
{
    protected $table = "logs_ip_cash_out";

    protected $fillable = [
        'ip',
        'data',
    ];
}