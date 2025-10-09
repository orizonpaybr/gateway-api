<?php

namespace App\Http\Controllers\Admin\Ajustes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\App;

class SegurancaController extends Controller
{
    public function index()
    {
        $setting = App::first();
        return view("admin.ajustes.gerais", compact('setting'));
    }

    public function update(Request $request)
    {
        if ($request->input('gerente_percentage')) {
            $gerente_active = $request->has('gerente_active');
            App::first()->update([
                'gerente_percentage' => (float) str_replace(',', '.', $request->gerente_percentage),
                'gerente_active' => $gerente_active
            ]);
            return back()->with('success', 'Porcentagem alterada com sucesso.');
        }

        $data = $request->except(['_token', '_method', 'gateway_logo', 'gateway_favicon']);
        $payload = [];

        foreach ($data as $key => $value) {
            if ($key === 'global_ips') {
                // Processar IPs globais - não codificar como JSON aqui pois o model já faz isso
                $ips = array_filter(array_map('trim', explode(',', $value)));
                $payload[$key] = $ips; // Deixar o Eloquent fazer a codificação JSON automaticamente
            } else {
                $payload[$key] = (
                    $key === 'gateway_name' ||
                    $key === 'cnpj' ||
                    $key === 'gateway_color'
                ) ? $value : (float) $value;
            }
        }

        $payload["taxa_cash_in_padrao"]  = (float) str_replace(',', '.', $payload["taxa_cash_in_padrao"] ?? 0);
        $payload["taxa_cash_out_padrao"]  = (float) str_replace(',', '.', $payload["taxa_cash_out_padrao"] ?? 0);
        $payload["taxa_fixa_padrao"]  = (float) str_replace(',', '.', $payload["taxa_fixa_padrao"] ?? 0);
        $payload["baseline"]  = (float) str_replace(',', '.', $payload["baseline"] ?? 0);
        $payload["taxa_fixa_pix"]  = (float) str_replace(',', '.', $payload["taxa_fixa_pix"] ?? 0);
        $payload["deposito_minimo"]  = (float) str_replace(',', '.', $payload["deposito_minimo"] ?? 0);
        $payload["saque_minimo"]  = (float) str_replace(',', '.', $payload["saque_minimo"] ?? 0);
        $payload["limite_saque_mensal"]  = (float) str_replace(',', '.', $payload["limite_saque_mensal"] ?? 0);
        $payload["taxa_saque_api_padrao"]  = (float) str_replace(',', '.', $payload["taxa_saque_api_padrao"] ?? 0);
        $payload["taxa_saque_cripto_padrao"]  = (float) str_replace(',', '.', $payload["taxa_saque_cripto_padrao"] ?? 0);
        
        // Processar campos de taxa flexível
        $payload["taxa_flexivel_valor_minimo"] = (float) str_replace(',', '.', $payload["taxa_flexivel_valor_minimo"] ?? 0);
        $payload["taxa_flexivel_fixa_baixo"] = (float) str_replace(',', '.', $payload["taxa_flexivel_fixa_baixo"] ?? 0);
        $payload["taxa_flexivel_percentual_alto"] = (float) str_replace(',', '.', $payload["taxa_flexivel_percentual_alto"] ?? 0);
        $payload["taxa_flexivel_ativa"] = $request->has('taxa_flexivel_ativa');

        // Processar campos de personalização de relatórios de ENTRADAS
        $payload["relatorio_entradas_mostrar_meio"] = $request->has('relatorio_entradas_mostrar_meio');
        $payload["relatorio_entradas_mostrar_transacao_id"] = $request->has('relatorio_entradas_mostrar_transacao_id');
        $payload["relatorio_entradas_mostrar_valor"] = $request->has('relatorio_entradas_mostrar_valor');
        $payload["relatorio_entradas_mostrar_valor_liquido"] = $request->has('relatorio_entradas_mostrar_valor_liquido');
        $payload["relatorio_entradas_mostrar_nome"] = $request->has('relatorio_entradas_mostrar_nome');
        $payload["relatorio_entradas_mostrar_documento"] = $request->has('relatorio_entradas_mostrar_documento');
        $payload["relatorio_entradas_mostrar_status"] = $request->has('relatorio_entradas_mostrar_status');
        $payload["relatorio_entradas_mostrar_data"] = $request->has('relatorio_entradas_mostrar_data');
        $payload["relatorio_entradas_mostrar_taxa"] = $request->has('relatorio_entradas_mostrar_taxa');

        // Processar campos de personalização de relatórios de SAÍDAS
        $payload["relatorio_saidas_mostrar_transacao_id"] = $request->has('relatorio_saidas_mostrar_transacao_id');
        $payload["relatorio_saidas_mostrar_valor"] = $request->has('relatorio_saidas_mostrar_valor');
        $payload["relatorio_saidas_mostrar_nome"] = $request->has('relatorio_saidas_mostrar_nome');
        $payload["relatorio_saidas_mostrar_chave_pix"] = $request->has('relatorio_saidas_mostrar_chave_pix');
        $payload["relatorio_saidas_mostrar_tipo_chave"] = $request->has('relatorio_saidas_mostrar_tipo_chave');
        $payload["relatorio_saidas_mostrar_status"] = $request->has('relatorio_saidas_mostrar_status');
        $payload["relatorio_saidas_mostrar_data"] = $request->has('relatorio_saidas_mostrar_data');
        $payload["relatorio_saidas_mostrar_taxa"] = $request->has('relatorio_saidas_mostrar_taxa');

        $imageFields = ['gateway_logo', 'gateway_favicon'];

        foreach ($imageFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();

                // Caminho: public/uploads
                $destination = public_path('uploads');
                if (!file_exists($destination)) {
                    mkdir($destination, 0775, true);
                }

                $file->move($destination, $filename);

                // Caminho acessível via navegador
                $payload[$field] = '/uploads/' . $filename;
            } else {
                unset($payload[$field]); // Corrigido: $payload, não $data
            }
        }

        // Atualiza as configurações
        $setting = App::first();
        if ($setting) {
            $setting->update($payload);
        }

        return back()->with('success', 'Dados alterados com sucesso!');
    }
}
