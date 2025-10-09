<?php

namespace App\Http\Controllers;

use App\Models\Unidade;
use Illuminate\Http\Request;

class UnidadeController extends Controller
{
    public function index()
    {
        $unidades = Unidade::all();
        return view("unidades.index", compact("unidades"));
    }

    public function create()
    {
        return view("unidades.create");
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            "nome_fantasia" => "required|string|max:255",
            "razao_social" => "required|string|max:128",
            "cnpj" => "required|max:20",
            "bandeira_id" => "required|exists:bandeiras,id",
        ]);
        Unidade::create($validatedData);
        return redirect()
            ->route("unidades.index")
            ->with("success", "Unidade criada com sucesso.");
    }

    public function edit(string $id)
    {
        $unidade = Unidade::findOrFail($id);
        return view("unidades.edit", compact("unidade"));
    }

    public function update(Request $request, string $id)
    {
        $unidade = Unidade::findOrFail($id);
        $validatedData = $request->validate([
            "nome_fantasia" => "required|string|max:255",
            "razao_social" => "required|string|max:128",
            "cnpj" => "required|max:20",
            "bandeira_id" => "required|exists:bandeiras,id",
        ]);
        $unidade->update($validatedData);
        return redirect()
            ->route("unidades.index")
            ->with("success", "Unidade atualizada com sucesso.");
    }

    public function destroy(string $id)
    {
        $unidade = Unidade::findOrFail($id);
        $unidade->delete();
        return redirect()
            ->route("unidades.index")
            ->with("success", "Unidade excluida com sucesso.");
    }

}
