<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Constants\UserPermission;

/**
 * Form Request para criação de usuários
 * 
 * Implementa validação robusta e reutilizável seguindo Laravel Best Practices
 */
class StoreUserRequest extends FormRequest
{
    use UserRequestTrait;
    
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Nota: O middleware ensure.admin já verifica a permissão,
     * mas mantemos esta verificação como camada adicional de segurança.
     */
    public function authorize(): bool
    {
        // Obter usuário da mesma forma que o middleware ensure.admin
        $user = $this->user() ?? $this->input('user_auth');
        
        // Se não há usuário, retornar false (o middleware já deve ter bloqueado)
        if (!$user) {
            return false;
        }
        
        // Apenas admins podem criar usuários
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
        return array_merge([
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                'regex:/^[a-zA-Z0-9_.-]+$/',
                'unique:users,username'
            ],
            'name' => 'required|string|min:3|max:255',
            'email' => $this->emailRules(),
            'password' => 'required|string|min:6|max:100',
            'telefone' => 'nullable|string|min:10|max:20',
            'cpf_cnpj' => $this->cpfCnpjRules(),
            'cpf' => 'nullable|string|size:11',
            'saldo' => 'nullable|numeric|min:0',
            'status' => $this->statusRules(),
            'permission' => $this->permissionRules(),
            
            // Relacionamentos
            'indicador_ref' => 'nullable|string|exists:users,code_ref',
            'gerente_id' => 'nullable|integer|exists:users,id',
        ], $this->addressRules(), $this->businessRules());
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'O nome de usuário é obrigatório',
            'username.unique' => 'Este nome de usuário já está em uso',
            'username.regex' => 'O nome de usuário pode conter apenas letras, números, pontos, hífens e sublinhados',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'Digite um email válido',
            'email.unique' => 'Este email já está cadastrado',
            'password.required' => 'A senha é obrigatória',
            'password.min' => 'A senha deve ter no mínimo 6 caracteres',
            'name.required' => 'O nome é obrigatório',
            'cpf_cnpj.unique' => 'Este CPF/CNPJ já está cadastrado',
            'status.in' => 'Status inválido',
            'permission.in' => 'Permissão inválida',
        ];
    }
}

