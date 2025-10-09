<?php

namespace App\Traits;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class ApiTrait
{
    public static function generateQRCode($data)
    {
        $api_key = $data['api-key'];
        $amount = $data['amount'];
        $name = $data['client']['email'];
        $document = $data['client']['document'];
        $email = $data['client']['email'];


        $dueDate = Carbon::now()->format('Y-m-d');
        $requestNumber = uniqid();

        $endpoint = env('API_URL') . '/v1/gateway/';
        $payload = [
            "api-key" => $api_key,
            "requestNumber" => $requestNumber,
            "dueDate" => $dueDate,
            "amount" => floatval($amount),
            "client" => [
                "name" => $name,
                "document" => $document,
                "email" => $email ?? "cliente@email.com"
            ]
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($endpoint, $payload);
        return $response;
    }

    public static function statusDeposit($data)
    {
        $endpoint = env('API_URL') . '/v1/webhook/';
        $response = Http::withHeaders(['Content-Type' => 'application/json'])->post($endpoint, $data);
        return $response;
    }
}
