<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Helper;

class DocumentacaoControlller extends Controller
{
    public function index()
    {
        $setting = Helper::getSetting();
        return view("documentacao", compact('setting'));
    }
}