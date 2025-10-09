<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GerenteApoio extends Model
{

    protected $table = "gerente_apoio";

    protected $fillable = ['titulo', 'descricao', 'imagem'];

    protected $timestamp = true;
}
