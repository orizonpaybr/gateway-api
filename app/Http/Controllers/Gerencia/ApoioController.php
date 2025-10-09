<?php

namespace App\Http\Controllers\Gerencia;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\GerenteApoio;
use Illuminate\Http\Request;

class ApoioController extends Controller
{
    public function index(Request $request)
    {
        $appSettings = App::first();
        $porcentagem = $appSettings ? $appSettings->gerente_percentage : 0;
                $gerente_active = $appSettings ? $appSettings->gerente_active : false;
        $apoios = GerenteApoio::get();
        return view('admin.ajustes.apoio', compact('apoios', 'porcentagem', 'gerente_active'));
    }

    public function create(Request $request)
    {

        $payload = [
            'titulo' => $request->input('titulo'),
            'descricao' => $request->input('descricao')
        ];

        $imageFields = ['imagem'];

        foreach ($imageFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();

                // Caminho: public/uploads
                $destination = public_path('uploads/material-apoio');
                if (!file_exists($destination)) {
                    mkdir($destination, 0775, true);
                }

                $file->move($destination, $filename);

                // Caminho acessível via navegador
                $payload[$field] = '/uploads/material-apoio/' . $filename;
            } else {
                unset($payload[$field]); // Corrigido: $payload, não $data
            }
        }

        GerenteApoio::create($payload);
        return response()->json(['success' => true, 'message' => 'Material criado com sucesso.']);
    }
    public function update($id, Request $request)
    {
        $payload = [
            'titulo' => $request->input('titulo'),
            'descricao' => $request->input('descricao')
        ];

        $imageFields = ['imagem'];

        foreach ($imageFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();

                // Caminho: public/uploads
                $destination = public_path('uploads/material-apoio');
                if (!file_exists($destination)) {
                    mkdir($destination, 0775, true);
                }

                $file->move($destination, $filename);

                // Caminho acessível via navegador
                $payload[$field] = '/uploads/material-apoio/' . $filename;
            } else {
                unset($payload[$field]); // Corrigido: $payload, não $data
            }
        }

        GerenteApoio::find($id)->update($payload);
        return response()->json(['success' => true, 'message' => 'Material alterado com sucesso.']);
    }
    public function destroy(Request $request)
    {
        //
    }
}
