<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para validação de atualização de status de depósito
 * 
 * Implementa:
 * - Validação de status permitidos
 * - Validação de ID do depósito
 * - Sanitização de entrada
 */
class UpdateDepositStatusRequest extends FormRequest
{
    /**
     * Determinar se o usuário está autorizado
     */
    public function authorize(): bool
    {
        return true; // Middleware 'ensure.admin' já valida permissão
    }

    /**
     * Regras de validação
     */
    public function rules(): array
    {
        return [
            'status' => 'required|string|in:PENDING,PAID_OUT,COMPLETED,CANCELLED,REJECTED',
        ];
    }

    /**
     * Mensagens de validação customizadas
     */
    public function messages(): array
    {
        return [
            'status.required' => 'O status é obrigatório',
            'status.string' => 'O status deve ser uma string',
            'status.in' => 'Status inválido. Valores permitidos: PENDING, PAID_OUT, COMPLETED, CANCELLED, REJECTED',
        ];
    }

    /**
     * Preparar dados para validação
     */
    protected function prepareForValidation(): void
    {
        // Garantir que status está em uppercase
        if ($this->has('status')) {
            $this->merge([
                'status' => strtoupper(trim($this->input('status', ''))),
            ]);
        }
    }
}

