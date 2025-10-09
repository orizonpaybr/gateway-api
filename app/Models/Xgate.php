<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Xgate extends Model
{
    protected $table = "xgate";

    protected $fillable = ['email', 'password', 'taxa_adquirente_entradas', 'taxa_adquirente_saidas'];
}
