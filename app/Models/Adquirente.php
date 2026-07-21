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
        "provider",
        "credentials",
        "is_default",
        "is_default_card_billet",
    ];

    protected $casts = [
        "credentials" => "encrypted:array",
        "status" => "boolean",
        "is_default" => "boolean",
        "is_default_card_billet" => "boolean",
    ];
}