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
        // Obter usuário da mesma forma que o middleware ensure.admin
        $user = $this->user() ?? $this->input('user_auth');
        
        // Se não há usuário, retornar false (o middleware já deve ter bloqueado)
        if (!$user) {
            return false;
        }
        
        // Apenas admins podem atualizar usuários
        return $user->permission == UserPermission::ADMIN;
    }

    /**
     * Handle a failed authorization attempt.
     * 
     * Retorna JSON ao invés de redirecionar para APIs
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Acesso negado. Apenas administradores podem realizar esta ação.'
            ], 403)->header('Access-Control-Allow-Origin', '*')
        );
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
            'telefone' => 'nullable|string|min:10|max:20',
            'cpf' => 'nullable|string|size:11',
            'data_nascimento' => 'nullable|date|before:today',
            'saldo' => 'nullable|numeric|min:0',
            'status' => $this->statusRules(),
            'permission' => $this->permissionRules(),
            
            // Relacionamentos
            'gerente_id' => 'nullable|integer|exists:users,id',
            'gerente_percentage' => 'nullable|numeric|min:0|max:100',
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
            'name.required' => 'O nome é obrigatório',
            'status.in' => 'Status inválido',
            'permission.in' => 'Permissão inválida',
            'taxa_percentual_deposito.max' => 'A taxa percentual não pode ser maior que 100%',
            'taxa_percentual_pix.max' => 'A taxa percentual não pode ser maior que 100%',
        ];
    }
}

