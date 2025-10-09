<?php

namespace App\Http\Controllers\Api;

use Illuminate\Validation\ValidationException;
use App\Traits\EfiTrait;
use Illuminate\Http\Request;

class BilletController
{
    use EfiTrait;
    public function charge(Request $request)
    {
        try {
            $validated = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.name' => ['required', 'string'],
                'items.*.value' => ['required', 'numeric'],
                'items.*.amount' => ['required', 'integer'],

                'payment' => ['required', 'array'],
                'payment.banking_billet' => ['required', 'array'],

                'payment.banking_billet.customer' => ['required', 'array'],
                'payment.banking_billet.customer.name' => ['required', 'string'],
                'payment.banking_billet.customer.cpf' => ['required', 'string'],
                'payment.banking_billet.customer.email' => ['required', 'email'],
                'payment.banking_billet.customer.phone_number' => ['required', 'string'],

                'payment.banking_billet.customer.address' => ['sometimes', 'array'],
                'payment.banking_billet.customer.address.street' => ['required_with:payment.banking_billet.customer.address', 'string'],
                'payment.banking_billet.customer.address.number' => ['required_with:payment.banking_billet.customer.address', 'string'],
                'payment.banking_billet.customer.address.neighborhood' => ['required_with:payment.banking_billet.customer.address', 'string'],
                'payment.banking_billet.customer.address.zipcode' => ['required_with:payment.banking_billet.customer.address', 'string'],
                'payment.banking_billet.customer.address.city' => ['required_with:payment.banking_billet.customer.address', 'string'],
                'payment.banking_billet.customer.address.complement' => ['nullable', 'string'],
                'payment.banking_billet.customer.address.state' => ['required_with:payment.banking_billet.customer.address', 'string'],

                'payment.banking_billet.expire_at' => ['required', 'date'],
                'payment.banking_billet.configurations' => ['required', 'array'],
                'payment.banking_billet.configurations.fine' => ['required', 'numeric'],
                'payment.banking_billet.configurations.interest' => ['required', 'numeric'],

                'payment.banking_billet.message' => ['sometimes', 'string'],
            ]);

            $response = self::requestBoletoEfi($request);
            return response()->json($response['data'], $response['status']);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function callbackCharge(Request $request)
    {
        $data = $request->all();
        \Log::debug('[+][EFI][BILLET][WEBHOOK][CHARGE]: '.json_encode($data));
        $notification = $data['notification'];
        self::webhookCharge($notification);
        
        return response()->json([], 200);
    }
}
