<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class IntegracaoController extends Controller
{
    public function index()
    {
        return view("profile.integracao");
    }

    public function utmfy(Request $request)
    {
        $integracao_utmfy = $request->integracao_utmfy;
        User::where("id", auth()->user()->id)->update(["integracao_utmfy" => $integracao_utmfy]);
        auth()->user()->integracao_utmfy = $integracao_utmfy;
        auth()->user()->save();
        auth()->user()->fresh();
        return back()->with('success', 'Dados salvos com sucesso!');
    }
}
