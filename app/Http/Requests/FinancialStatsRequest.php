<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para validação de filtros de estatísticas financeiras
 */
class FinancialStatsRequest extends FormRequest
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
            'periodo' => 'sometimes|string|in:hoje,mes,7d,30d,total',
        ];
    }

    /**
     * Mensagens de validação customizadas
     */
    public function messages(): array
    {
        return [
            'periodo.in' => 'Período deve ser: hoje, mes, 7d, 30d ou total',
        ];
    }
}

