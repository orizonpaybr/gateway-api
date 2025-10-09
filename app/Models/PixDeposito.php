<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PixDeposito extends Model
{
    protected $table = "pix_deposito";

    protected $fillable = [
        'value',
        'email',
        'code',
        'status',
        'data',
        'user_id',
    ];

    public function user()
    {
        return $this->hasOne(User::class);
    }
}