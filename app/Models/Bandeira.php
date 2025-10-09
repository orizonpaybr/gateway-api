<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bandeira extends Model
{

    use HasFactory;
    protected $fillable = ['nome', 'grupo_economico_id'];

    public function grupo_economico()
    {
        return $this->belongsTo(GrupoEconomico::class);
    }
}
