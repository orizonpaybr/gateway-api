<?php

namespace App\Http\Controllers\Admin\Ajustes;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Nivel;
use Illuminate\Http\Request;

class NivelController extends Controller
{
    public function index(Request $request)
    {
        $niveis = Nivel::get();
        $niveis_ativo = App::first()->niveis_ativo;
        return view('admin.ajustes.niveis', compact('niveis', 'niveis_ativo'));
    }

    public function store(Request $request)
    {
        $nivel = Nivel::create($request->only(['nome', 'cor', 'minimo', 'maximo']));

        if ($request->hasFile('icone')) {
            $file = $request->file('icone');
            $filename = uniqid() . '.' . $file->getClientOriginalExtension();

            // Caminho: public/uploads
            $destination = public_path('uploads/niveis');
            if (!file_exists($destination)) {
                mkdir($destination, 0775, true);
            }
            // dd($file->move($destination, $filename));
            $file->move($destination, $filename);

            $nivel->icone = '/uploads/niveis/' . $filename;
            $nivel->save();
        }

        return response()->json(['success' => true, 'message' => 'Nivel cadastrado com sucesso!']);
    }

    public function update(Request $request, $id)
    {
        $nivel = Nivel::findOrFail($id);
        $nivel->update($request->only(['nome', 'cor', 'minimo', 'maximo']));
        if ($request->hasFile('icone')) {
            $file = $request->file('icone');
            $filename = uniqid() . '.' . $file->getClientOriginalExtension();

            // Caminho: public/uploads
            $destination = public_path('uploads/niveis');
            if (!file_exists($destination)) {
                mkdir($destination, 0775, true);
            }
            // dd($file->move($destination, $filename));
            $file->move($destination, $filename);

            $nivel->icone = '/uploads/niveis/' . $filename;
            $nivel->save();
        }

        return response()->json(['success' => true, 'message' => 'Nivel atualizado com sucesso!']);
    }

    public function destroy($id)
    {
        Nivel::destroy($id);
        return response()->json(['success' => true, 'message' => 'Nivel excluído com sucesso!']);
    }

    public function activeNiveis(Request $request)
    {
        //dd((bool)$request->input('niveis_ativo'));
        App::first()->update(['niveis_ativo' => $request->boolean('niveis_ativo')]);

        $status = $request->boolean('niveis_ativo') ? 'ativado' : 'desativado';
        return response()->json(['success' => true, 'message' => "Sistema de níveis $status com sucesso!"]);
    }
}
