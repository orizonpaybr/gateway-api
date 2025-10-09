<?php

namespace App\Traits;

use App\Models\Solicitacoes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class UtmfyTrait
{
    /**
     * @param 'pix'|'credit_card' | 'boleto' $method
     * @param 'waiting_payment' | 'paid' | 'refunded'
     * @param Solicitacoes $data
     * @param string $apiToken
     * @param string $ip
     * @param string $description
     */
    public static function gerarUTM($method, $status, $data, $apiToken, $ip, $description)
    {

        $dataAtual = Carbon::now()->format('Y-m-d H:i:s');
        Http::withHeaders([
            'x-api-token' => $apiToken,
        ])->post('https://api.utmify.com.br/api-credentials/orders', [
            'orderId' => $data['idTransaction'],
            'platform' => env('APP_NAME'),
            'paymentMethod' => $method,
            'status' => $status,
            'createdAt' => $dataAtual,
            'approvedDate' => null,
            'refundedAt' => null,
            'customer' => [
                'name' => $data['client_name'],
                'email' => $data['client_email'],
                'phone' => $data['client_telefone'],
                'document' => $data['client_document'],
                'country' => 'BR',
                'ip' => $ip,
            ],
            'products' => [
                [
                    'id' => uniqid(),
                    'name' =>  $description,
                    'planId' => null,
                    'planName' => null,
                    'quantity' => 1,
                    'priceInCents' => (int) $data['amount'] * 100,
                ],
            ],
            'trackingParameters' => [
                'src' => null,
                'sck' => null,
                'utm_source' => null,
                'utm_campaign' => null,
                'utm_medium' => null,
                'utm_content' => null,
                'utm_term' => null,
            ],
            'commission' => [
                'totalPriceInCents' => (int) $data['amount'] * 100,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => 0,
            ],
            'isTest' => false,
        ]);
    }
}
