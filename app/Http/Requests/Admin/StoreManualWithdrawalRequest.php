<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'exists:users,user_id'],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'O usuário é obrigatório.',
            'user_id.exists' => 'Usuário não encontrado.',
            'amount.required' => 'Informe um valor para o saque.',
            'amount.numeric' => 'O valor deve ser numérico.',
            'amount.min' => 'O valor precisa ser maior que zero.',
            'description.max' => 'A descrição deve ter no máximo 255 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('amount')) {
            $this->merge([
                'amount' => (float) $this->input('amount'),
            ]);
        }
    }
}

