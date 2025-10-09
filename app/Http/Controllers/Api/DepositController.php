<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adquirente;
use Illuminate\Http\Request;
use App\Traits\CashtimeTrait;
use App\Traits\MercadoPagoTrait;
use App\Traits\EfiTrait;
use App\Traits\PagarMeTrait;
use App\Traits\XgateTrait;
use App\Traits\WitetecTrait;
use App\Traits\PixupTrait;
use App\Traits\BSPayTrait;
use App\Traits\WooviTrait;
use App\Traits\AsaasTrait;
use App\Traits\PrimePay7Trait;
use App\Traits\XDPagTrait;
use App\Models\Solicitacoes;
use App\Models\App;
use App\Helpers\Helper;
use App\Helpers\ApiResponseStandardizer;

/**
 * @OA\Info(
 *     title="API Rest PIX",
 *     version="1.0.0",
 *     description="Documentação"
 * )
 */

class DepositController extends Controller
{

    public function makeDeposit(Request $request)
    {
        // Verificar se o usuário está autenticado
        $user = $request->user();
        \Log::info('DepositController - Verificação de usuário', [
            'user_from_request' => $user ? 'Presente' : 'Ausente',
            'user_id' => $user ? $user->id : 'N/A',
            'request_data' => $request->all()
        ]);
        
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não autenticado'], 401);
        }
        
        $setting = App::first();
        if (!$setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        $default = Helper::adquirenteDefault($user->user_id);
        \Log::info('DepositController - Adquirente default', ['adquirente' => $default]);
        if (!$default) {
            \Log::info('DepositController - Nenhum adquirente configurado', []);
            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        // Verificar valor mínimo de depósito - priorizar configuração específica do usuário
        $valorMinimoDeposito = $user->valor_minimo_deposito ?? $setting->deposito_minimo;
        
        if ($valorMinimoDeposito > 0 && $request->amount < $valorMinimoDeposito) {
            $valorret = number_format($valorMinimoDeposito, '2', ',', '.');
            return response()->json([
                'status' => 'error',
                'message' => "O valor mínimo de depósito é de R$ $valorret."
            ], 401);
        }
        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'secret' => ['required', 'string'],
                'amount' => ['required'],
                'debtor_name' => ['required', 'string'],
                'email' => ['required', 'string', 'email'],
                'debtor_document_number' => ['nullable', 'string'],
                'phone' => ['required', 'string'],
                'method_pay' => ['required', 'string'],
                'postback' => ['required', 'string'],
                'split_email' => ['nullable', 'string', 'email'],
                'split_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422); // Status code 422 para erros de validação
        }

        \Log::info('DepositController - Executando switch para adquirente', ['adquirente' => $default]);
        switch($default){
            case 'cashtime':
                \Log::info('DepositController - Executando CashtimeTrait', []);
                $response = CashtimeTrait::requestDepositCashtime($request);
            break;
            case 'mercadopago':
                \Log::info('DepositController - Executando MercadoPagoTrait', []);
                $response = MercadoPagoTrait::requestDepositMercadoPago($request);
            break;
            case 'efi':
                \Log::info('DepositController - Executando EfiTrait', []);
                $response = EfiTrait::requestDepositEfi($request);
            break;
            case 'pagarme':
                \Log::info('DepositController - Executando PagarMeTrait', []);
                $response = PagarMeTrait::requestDepositPagarme($request);
            break;
            case 'xgate':
                \Log::info('DepositController - Executando XgateTrait', []);
                $response = XgateTrait::requestDepositXgate($request);
            break;
            case 'witetec':
                \Log::info('DepositController - Executando WitetecTrait', []);
                $response = WitetecTrait::requestDepositWitetec($request);
            break;
            case 'pixup':
                \Log::info('DepositController - Executando PixupTrait', []);
                $response = PixupTrait::requestDepositPixup($request);
            break;
            case 'bspay':
                \Log::info('DepositController - Executando BSPayTrait', []);
                $response = BSPayTrait::requestDepositBSPay($request);
            break;
            case 'woovi':
                \Log::info('DepositController - Executando WooviTrait', []);
                $response = WooviTrait::requestPaymentWoovi($request);
            break;
            case 'asaas':
                \Log::info('DepositController - Executando AsaasTrait', []);
                $response = AsaasTrait::requestDepositAsaas($request);
            break;
            case 'primepay7':
                \Log::info('DepositController - Executando PrimePay7Trait', []);
                $response = PrimePay7Trait::generateQrCodePrimePay7($request);
            break;
            case 'xdpag':
                \Log::info('DepositController - Executando XDPagTrait', []);
                $response = XDPagTrait::requestDepositXDPag($request);
            break;
            default:
                \Log::info('DepositController - Adquirente não suportado', ['adquirente' => $default]);
                return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado.'], 500);
        }
        
        // Verificar se a resposta foi definida
        if (!isset($response)) {
            return response()->json(['status' => 'error', 'message' => 'Erro ao processar depósito.'], 500);
        }
        
        // Se passar pela validação, processar o depósito
        if ($response['status'] === 200) {
            // Padronizar a resposta usando o sistema de padronização
            $standardizedResponse = ApiResponseStandardizer::standardizeDepositResponse(
                $response['data'], 
                $request->amount
            );
            return response()->json($standardizedResponse, 200);
        }
        
        return response()->json($response['data'], $response['status']);
    }

    public function statusDeposito(Request $request)
    {
        $deposit = Solicitacoes::where('idTransaction', $request->idTransaction)
            ->orWhere('externalreference', $request->idTransaction)
            ->first();
        if (!$deposit) {
            return response()->json(['status' => 'NOT_FOUND']);
        }
        return response()->json(['status' => $deposit->status]);
    }
}
