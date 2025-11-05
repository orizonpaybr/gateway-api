<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\AffiliateSettings;

/**
 * Form Request para configurações de afiliados
 */
class AffiliateSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Apenas admins podem configurar afiliados
        return $this->user() && $this->user()->permission == \App\Constants\UserPermission::ADMIN;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'is_affiliate' => 'required|boolean',
            'affiliate_percentage' => [
                'required_if:is_affiliate,1',
                'numeric',
                'min:' . AffiliateSettings::MIN_PERCENTAGE,
                'max:' . AffiliateSettings::MAX_PERCENTAGE,
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'is_affiliate.required' => 'O campo de ativação de afiliado é obrigatório',
            'is_affiliate.boolean' => 'O campo de ativação de afiliado deve ser verdadeiro ou falso',
            'affiliate_percentage.required_if' => 'A porcentagem é obrigatória quando o afiliado está ativo',
            'affiliate_percentage.numeric' => 'A porcentagem deve ser um número',
            'affiliate_percentage.min' => 'A porcentagem de affiliate deve ser no mínimo ' . AffiliateSettings::MIN_PERCENTAGE,
            'affiliate_percentage.max' => 'A porcentagem de affiliate deve estar entre ' . AffiliateSettings::MIN_PERCENTAGE . ' e ' . AffiliateSettings::MAX_PERCENTAGE,
        ];
    }
}

