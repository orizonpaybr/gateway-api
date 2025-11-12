<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para validação de filtros de transações financeiras
 */
class FinancialTransactionsRequest extends FormRequest
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
            'page' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|string|in:PAID_OUT,COMPLETED,PENDING,WAITING_FOR_APPROVAL,CANCELLED,REJECTED',
            'tipo' => 'sometimes|string|in:deposito,saque',
            'busca' => 'sometimes|string|max:100',
            'data_inicio' => 'sometimes|date_format:Y-m-d',
            'data_fim' => 'sometimes|date_format:Y-m-d',
        ];
    }

    /**
     * Mensagens de validação customizadas
     */
    public function messages(): array
    {
        return [
            'page.integer' => 'A página deve ser um número inteiro',
            'page.min' => 'A página deve ser maior que zero',
            'limit.integer' => 'O limite deve ser um número inteiro',
            'limit.min' => 'O limite deve ser maior que zero',
            'limit.max' => 'O limite não pode ser maior que 100',
            'status.in' => 'Status inválido',
            'tipo.in' => 'Tipo deve ser "deposito" ou "saque"',
            'busca.max' => 'A busca não pode ter mais de 100 caracteres',
            'data_inicio.date_format' => 'Data de início deve estar no formato YYYY-MM-DD',
            'data_fim.date_format' => 'Data de fim deve estar no formato YYYY-MM-DD',
        ];
    }

    /**
     * Preparar dados para validação
     */
    protected function prepareForValidation(): void
    {
        // Sanitizar busca
        if ($this->has('busca')) {
            $this->merge([
                'busca' => trim($this->input('busca', '')),
            ]);
        }
    }
}

