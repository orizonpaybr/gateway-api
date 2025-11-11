<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:PENDING,COMPLETED,PAID_OUT,CANCELLED,FAILED,PROCESSING,all,ALL'],
            'busca' => ['sometimes', 'string', 'max:100'],
            'data_inicio' => ['sometimes', 'date'],
            'data_fim' => ['sometimes', 'date', 'after_or_equal:data_inicio'],
            'tipo' => ['sometimes', 'string', 'in:manual,automatico,all'],
        ];
    }
}


