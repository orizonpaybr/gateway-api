<?php

namespace App\Http\Controllers\Admin\Ajustes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SmtpController extends Controller
{
    public function index()
    {
        return view("admin.ajustes.smtp");
    }
}