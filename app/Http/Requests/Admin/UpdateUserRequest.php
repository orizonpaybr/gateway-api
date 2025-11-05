<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Constants\UserPermission;

/**
 * Form Request para atualização de usuários
 * 
 * Implementa validação robusta e reutilizável seguindo Laravel Best Practices
 */
class UpdateUserRequest extends FormRequest
{
    use UserRequestTrait;
    
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Apenas admins podem atualizar usuários
        return $this->user() && $this->user()->permission == UserPermission::ADMIN;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id'); // ID do usuário sendo atualizado
        
        return array_merge([
            'name' => 'sometimes|required|string|min:3|max:255',
            'email' => $this->emailRules($userId),
            'password' => 'nullable|string|min:6|max:100',
            'telefone' => 'nullable|string|min:10|max:20',
            'cpf_cnpj' => $this->cpfCnpjRules($userId),
            'cpf' => 'nullable|string|size:11',
            'data_nascimento' => 'nullable|date|before:today',
            'saldo' => 'nullable|numeric|min:0',
            'status' => $this->statusRules(),
            'permission' => $this->permissionRules(),
            
            // Relacionamentos
            'gerente_id' => 'nullable|integer|exists:users,id',
        ], $this->addressRules(), $this->businessRules(), $this->customFeesRules(), $this->flexibleSystemRules());
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'Digite um email válido',
            'email.unique' => 'Este email já está cadastrado',
            'password.min' => 'A senha deve ter no mínimo 6 caracteres',
            'name.required' => 'O nome é obrigatório',
            'cpf_cnpj.unique' => 'Este CPF/CNPJ já está cadastrado',
            'status.in' => 'Status inválido',
            'permission.in' => 'Permissão inválida',
            'taxa_percentual_deposito.max' => 'A taxa percentual não pode ser maior que 100%',
            'taxa_percentual_pix.max' => 'A taxa percentual não pode ser maior que 100%',
        ];
    }
}

