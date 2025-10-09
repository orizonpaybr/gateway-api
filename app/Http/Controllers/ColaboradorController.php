<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use Illuminate\Http\Request;

class ColaboradorController extends Controller
{
    public function index()
    {
        $colaboradors = Colaborador::all();
        return view("colaboradors.index", compact("colaboradors"));
    }

    public function create()
    {
        return view("colaboradors.create");
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
            "email" => "required|string|max:255",
            "cpf" => "required|max:11",
            "unidade_id" => "required|exists:unidades,id",
        ]);
        Colaborador::create($validatedData);
        return redirect()
            ->route("colaboradors.index")
            ->with("success", "Colaborador criado com sucesso.");
    }

    public function edit(string $id)
    {
        $colaborador = Colaborador::findOrFail($id);
        return view("colaboradors.edit", compact("colaborador"));
    }

    public function update(Request $request, string $id)
    {
        $colaborador = Colaborador::findOrFail($id);
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
            "email" => "required|string|max:255",
            "cpf" => "required|max:11",
            "unidade_id" => "required|exists:unidades,id",
        ]);
        $colaborador->update($validatedData);
        return redirect()
            ->route("colaboradors.index")
            ->with("success", "Colaborador atualizada com sucesso.");
    }

    public function destroy(string $id)
    {
        $colaborador = Colaborador::findOrFail($id);
        $colaborador->delete();
        return redirect()
            ->route("colaboradors.index")
            ->with("success", "Colaborador excluida com sucesso.");
    }
}
