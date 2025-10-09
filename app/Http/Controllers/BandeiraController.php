<?php

namespace App\Http\Controllers;

use App\Models\Bandeira;
use Illuminate\Http\Request;

class BandeiraController extends Controller
{
    public function index()
    {
        $bandeiras = Bandeira::all();
        return view("bandeiras.index", compact("bandeiras"));
    }

    public function create()
    {
        return view("bandeiras.create");
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
            "grupo_economico_id" => "required|exists:grupo_economicos,id",
        ]);
        Bandeira::create($validatedData);
        return redirect()
            ->route("bandeiras.index")
            ->with("success", "Bandeira criada com sucesso.");
    }

    public function edit(string $id)
    {
        $bandeira = Bandeira::findOrFail($id);
        return view("bandeiras.edit", compact("bandeira"));
    }

    public function update(Request $request, string $id)
    {
        $bandeira = Bandeira::findOrFail($id);
        $validatedData = $request->validate([
            "nome" => "required|string|max:255",
            "grupo_economico_id" => "required|exists:grupo_economicos,id",
        ]);
        $bandeira->update($validatedData);
        return redirect()
            ->route("bandeiras.index")
            ->with("success", "Bandeira atualizada com sucesso.");
    }

    public function destroy(string $id)
    {
        $bandeira = Bandeira::findOrFail($id);
        $bandeira->delete();
        return redirect()
            ->route("bandeiras.index")
            ->with("success", "Bandeira excluida com sucesso.");
    }

}
