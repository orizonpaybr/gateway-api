<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GrupoEconomico;

class GrupoEconomicoController extends Controller
{
    public function index()
    {
        $grupo_economicos = GrupoEconomico::all();
        return view("grupo_economicos.index", compact("grupo_economicos"));
    }

    public function create()
    {
        return view("grupo_economicos.create");
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
        ]);
        GrupoEconomico::create($validatedData);
        return redirect()
            ->route("grupo_economicos.index")
            ->with("success", "Grupo Economico criado com sucesso.");
    }

    public function edit(string $id)
    {
        $grupo_economico = GrupoEconomico::findOrFail($id);
        return view("grupo_economicos.edit", compact("grupo_economico"));
    }

    public function update(Request $request, string $id)
    {
        $grupo_economico = GrupoEconomico::findOrFail($id);
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
        ]);
        $grupo_economico->update($validatedData);
        return redirect()
            ->route("grupo_economicos.index")
            ->with("success", "Grupo Economico atualizado com sucesso.");
    }

    public function destroy(string $id)
    {
        $grupo_economico = GrupoEconomico::findOrFail($id);
        $grupo_economico->delete();
        return redirect()
            ->route("grupo_economicos.index")
            ->with("success", "Grupo Economico excluido com sucesso.");
    }
}