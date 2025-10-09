<?php

namespace App\Http\Controllers\Admin\Transacoes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProcurarController extends Controller
{
    public function index()
    {
        return view("admin.transacoes.procurar");
    }
}