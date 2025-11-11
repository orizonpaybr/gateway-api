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
        // Permitir "sem limite" quando não enviado ou vazio. Se vier valor, validar como numérico >= 0
        $request->validate([
            'saque_automatico' => 'boolean',
            'limite_saque_automatico' => 'nullable|numeric|min:0'
        ]);

        $config = App::first();
        if (!$config) {
            return back()->with('error', 'Configurações não encontradas.');
        }

        // Interpretar vazio como NULL (sem limite)
        $limiteRaw = $request->input('limite_saque_automatico');
        $limite = null;
        if ($limiteRaw !== null && $limiteRaw !== '') {
            $limite = (float) str_replace(',', '.', $limiteRaw);
        }

        $config->update([
            'saque_automatico' => $request->has('saque_automatico'),
            'limite_saque_automatico' => $limite
        ]);

        return back()->with('success', 'Configurações de saque atualizadas com sucesso!');
    }
}
