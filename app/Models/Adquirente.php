<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adquirente extends Model
{
    protected $fillable = [
        "adquirente",
        "status",
        "url",
        "referencia",
        "is_default",
        "is_default_card_billet",
    ];
}