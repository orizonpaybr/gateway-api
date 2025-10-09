<?php

namespace App\Services;

use App\DTO\WitetecDTO\DepositDTO;
use App\DTO\WitetecDTO\ItemDTO;
use App\DTO\WitetecDTO\WithdrawDTO;
use App\DTO\WitetecDTO\Enums\DepositMethod;
use App\DTO\WitetecDTO\Enums\WithdrawMethod;
use Illuminate\Support\Facades\Http;

class WitetecService
{
    private string $baseUrl;
    private string $apiToken;

    public function __construct(string $baseUrl, string $apiToken)
    {
        $this->baseUrl = $baseUrl;
        $this->apiToken = $apiToken;
    }

    public function deposit(DepositDTO $data)
    {
        $payload = [
            "amount" => $data->amount,
            "method" => $data->method->value, // usa ->value no enum
            "customer" => [
                "name" => $data->customer->name,
                "email" => $data->customer->email,
                "phone" => $data->customer->phone,
                "documentType" => $data->customer->documentType,
                "document" => $data->customer->document,
            ],
            "metadata" => [
                "sellerExternalRef" => uniqid("REF_")
            ],
            "items" => array_map(fn(ItemDTO $item) => [
                "title" => $item->title,
                "amount" => $item->amount,
                "quantity" => $item->quantity,
                "tangible" => $item->tangible,
                "externalRef" => $item->externalRef,
            ], $data->items),
        ];

        if ($data->method === DepositMethod::CREDIT_CARD) {
            $payload["card"] = [
                "number" => $data->card->number,
                "holderName" => $data->card->holderName,
                "holderDocument" => $data->card->holderDocument,
                "expirationMonth" => $data->card->expirationMonth,
                "expirationYear" => $data->card->expirationYear,
                "cvv" => $data->card->cvv,
            ];
        }
        //dd($payload);

        $response = Http::withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiToken,
                ])->post($this->baseUrl . "/transactions", $payload);

        return $response->json();
    }

    public function withdraw(WithdrawDTO $data)
    {
        $payload = [
            "amount" => $data->amount,
            "pixKey" => $data->pixKey,
            "pixKeyType" => $data->pixKeyType->value, // usa ->value no enum
            "method" => $data->method,
        ];

        $response = Http::withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiToken,
                ])->post($this->baseUrl . "/withdrawals", $payload);

        return $response->json();
    }
}
