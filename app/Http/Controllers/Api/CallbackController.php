<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CallbackController extends Controller
{
    public function webhookPagarme(Request $request)
    {
        return response()->json(['received' => true]);
    }
}
