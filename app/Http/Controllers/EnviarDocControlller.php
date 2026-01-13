<?php

namespace App\Http\Controllers;

use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnviarDocControlller extends Controller
{
    public function index()
    {

        if (auth()->user()->status === 1) {
            return redirect()->route('dashboard')->with(['success' => "Sua conta já está ativa."]);
        }

        return view("profile.enviardoc");
    }

    public function enviarDocs($id, Request $request)
    {


        //dd($request);
        try {
            // Validação dinâmica baseada no tipo de documento
            $validationRules = [
                'data_nascimento' => 'required',
                'cpf_cnpj' => 'required|string',
                'cep' => 'required|string',
                'rua' => 'required|string',
                'numero_residencia' => 'required|string',
                'complemento' => 'nullable|string',
                'bairro' => 'required|string',
                'cidade' => 'required|string',
                'estado' => 'required|string',
                'media_faturamento' => 'required|string',
                'foto_rg_frente' => 'required|mimes:jpeg,png,jpg,gif,webp|max:10240',
                'foto_rg_verso' => 'required|mimes:jpeg,png,jpg,gif,webp|max:10240',
                'selfie_rg' => 'required|mimes:jpeg,png,jpg,gif,webp|max:10240',
            ];

            // Verifica se é CNPJ (14 dígitos) e adiciona validação do contrato social
            $cpfCnpjLimpo = preg_replace('/\D/', '', $request->cpf_cnpj);
            if (strlen($cpfCnpjLimpo) === 14) {
                $validationRules['contrato_social'] = 'required|mimes:jpeg,png,jpg,gif,webp,pdf|max:10240';
            }

            $request->validate($validationRules);

            $path = uniqid();
            $fotoRgFrente = self::salvarArquivo($request, 'foto_rg_frente', $path);
            $fotoRgVerso  = self::salvarArquivo($request, 'foto_rg_verso', $path);
            $selfieRg     = self::salvarArquivo($request, 'selfie_rg', $path);
            
            // Salva contrato social apenas se for CNPJ
            $contratoSocial = null;
            if (strlen($cpfCnpjLimpo) === 14 && $request->hasFile('contrato_social')) {
                $contratoSocial = self::salvarArquivo($request, 'contrato_social', $path);
            }


            // Salvar os caminhos corretamente no banco de dados
            $updateData = [
                'data_nascimento' => $request->data_nascimento,
                'cpf_cnpj' => $request->cpf_cnpj,
                'cep' => $request->cep,
                'rua' => $request->rua,
                'numero_residencia' => $request->numero_residencia,
                'complemento' => $request->complemento,
                'bairro' => $request->bairro,
                'cidade' => $request->cidade,
                'estado' => $request->estado,
                'media_faturamento' => $request->media_faturamento,
                'foto_rg_frente' => $fotoRgFrente ?? "",
                'foto_rg_verso' => $fotoRgVerso ?? "",
                'selfie_rg' => $selfieRg ?? "",
                'status' => UserStatus::PENDING,
            ];

            // Adiciona contrato social apenas se for CNPJ
            if ($contratoSocial) {
                $updateData['contrato_social'] = $contratoSocial;
            }

            DB::table('users')->where('id', $id)->update($updateData);

            return redirect()
                ->route("dashboard")
                ->with("success", "Documentos enviados com sucesso.");
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->validator)
                ->withInput();
        }
    }

    public static function salvarArquivo($request, $inputName)
    {
        if ($request->hasFile($inputName)) {
            $file = $request->file($inputName);
            
            // Validação rigorosa de segurança
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            $extension = strtolower($file->getClientOriginalExtension());
            
            if (!in_array($extension, $allowedExtensions)) {
                Log::warning('Tentativa de upload de arquivo não permitido', [
                    'extension' => $extension,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                throw new \InvalidArgumentException('Tipo de arquivo não permitido: ' . $extension);
            }
            
            // Verificar MIME type para prevenir arquivos maliciosos
            $allowedMimes = [
                'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'
            ];
            
            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, $allowedMimes)) {
                Log::warning('Tentativa de upload com MIME type suspeito', [
                    'mime_type' => $mimeType,
                    'extension' => $extension,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                throw new \InvalidArgumentException('Tipo MIME não permitido: ' . $mimeType);
            }
            
            // Renomear arquivo com extensão segura
            $filename = Str::uuid() . '.' . $extension;

            // Salvar em public/uploads com melhor segurança
            $destination = public_path('uploads');
            if (!file_exists($destination)) {
                mkdir($destination, 0755, true);
            }

            if ($file->move($destination, $filename)) {
                return 'uploads/' . $filename;
            } else {
                Log::error("Erro ao mover arquivo $inputName");
                return null;
            }
        }

        return null;
    }
}
