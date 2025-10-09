<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\App;

class SaqueConfigController extends Controller
{
    public function index()
    {
        $config = App::first();
        return view('admin.saque-config', compact('config'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'saque_automatico' => 'boolean',
            'limite_saque_automatico' => 'required|numeric|min:0'
        ]);

        $config = App::first();
        if (!$config) {
            return back()->with('error', 'Configurações não encontradas.');
        }

        $config->update([
            'saque_automatico' => $request->has('saque_automatico'),
            'limite_saque_automatico' => (float) str_replace(',', '.', $request->limite_saque_automatico)
        ]);

        return back()->with('success', 'Configurações de saque atualizadas com sucesso!');
    }
}
