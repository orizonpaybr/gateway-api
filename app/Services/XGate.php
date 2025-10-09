<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class XGate
{
    public string $baseUrl;
    public string $token;
    public array $currency;
    public string $customerId;
    public array $pixKey;
    public array $cryptoCurrency;

    public function __construct()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
    }

    public function genPayment($data)
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $this->generateToken();
        $this->getCurrencyDeposit();

        $payload = [];
        $payload['amount'] = $data['amount'];
        $payload['customer']['name'] = $data['debtor_name'];
        $payload['customer']['phone'] = $data['phone'];
        $payload['customer']['email'] = $data['email'];
        $payload['customer']['document'] = $data['debtor_document_number'];
        $payload['currency'] = $this->currency;

        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->post($this->baseUrl . "deposit", $payload);

        if ($response->successful()) {
            return $response->json()['data'];
        }
    }

    public function genWithdraw($data)
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $this->generateToken();
        $this->genCustomer($data);
        $this->getCurrencyWithdraw();
        $this->genPixKey($data);

        $payload = [];
        $payload['amount'] = $data['amount'];
        $payload['customerId'] = $this->customerId;
        $payload['currency'] = $this->currency;
        $payload['pixKey'] = $this->pixKey;

        $response = Http::withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Força IPv4 globalmente
            ],
        ])->withHeaders(['Authorization' => "Bearer " . $this->token])->post($this->baseUrl . "withdraw", $payload);
        //dd($response->json());
        if ($response->successful()) {
            return $response->json()['data'];
        } else {
            return $response->json();
        }
    }

    public function genWithdrawCrypto($data, $user)
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $this->generateToken();
        $this->genCustomer($user);

        $data['customerId'] = $this->customerId;

        $response = Http::withOptions([
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Força IPv4 globalmente
            ],
        ])->withHeaders(['Authorization' => "Bearer " . $this->token])->post($this->baseUrl . "withdraw", $data);

        return $response->json();
    }

    public function genCustomer($data)
    {

        $this->baseUrl = "https://api.xgateglobal.com/";

        $id = uniqid();
        $payload = [];
        $payload['name'] = "Cliente {$id} de " . $data['user']->name;
        $payload['notValidationDuplicated'] = true;

        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->post($this->baseUrl . "customer", $payload);

        if ($response->successful()) {
            $this->customerId = $response->json()['customer']['_id'];
        }
    }

    public function genPixKey($data)
    {
       
        $this->baseUrl = "https://api.xgateglobal.com/";
        $this->generateToken();
        $payload = [];
        $payload['type'] = strtoupper($data['pixKeyType']);
        $payload['key'] = $data['pixKey'];
        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->post($this->baseUrl . "pix/customer/{$this->customerId}/key", $payload);

        if ($response->successful()) {
            $this->pixKey = $response->json()['key'];
            return $response->json()['key'];
        }
    }

    public function generateToken()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $setting = \App\Models\Xgate::first();
        if (!$setting) {
            return false; // Retorna falso se nenhuma configuração for encontrada
        }
        $payload = [];
        $payload['email'] = $setting->email;
        $payload['password'] = $setting->password;

        $response = Http::post($this->baseUrl . "auth/token", $payload);

        if ($response->successful()) {
            $this->token = $response->json()['token'];
            return $response->json()['token'];
        }
        return false;
    }

    public function getCurrencyDeposit()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->get($this->baseUrl . "deposit/company/currencies");
        $this->currency = $response->json()[0];
    }

    public function getCurrencyWithdraw()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->get($this->baseUrl . "withdraw/company/currencies");
        $this->currency = $response->json()[0];
    }

    public function getCryptoDeposit()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->get($this->baseUrl . "deposit/company/cryptocurrencies");
        $this->cryptoCurrency = $response->json()[0];
    }

    public function getPublicKey()
    {
        $this->baseUrl = "https://api.xgateglobal.com/";
        $response = Http::withHeaders(['Authorization' => "Bearer " . $this->token])->get($this->baseUrl . "crypto/customer/{$this->customerId}/wallet");
       
        $this->cryptoCurrency = $response->json()[0];
    }

    public function getNetworks()
    {
        if($token = $this->generateToken()){
            $this->baseUrl = "https://api.xgateglobal.com/";
            $response = Http::withHeaders(['Authorization' => "Bearer " . $token])->get($this->baseUrl . "withdraw/company/blockchain-networks");
            return $response->json();
        } else {
             return null;
        }
    }
}
