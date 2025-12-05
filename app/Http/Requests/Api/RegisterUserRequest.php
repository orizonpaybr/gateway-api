<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'required|string|regex:/^[\pL\pN\s\'\-]+$/u|unique:users,username|max:255',
            'name' => 'required|string|max:255|regex:/^[\pL\s\'\-]+$/u',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'telefone' => 'required|string|unique:users,telefone',
            'cpf_cnpj' => 'required|string|unique:users,cpf_cnpj',
            'gender' => 'required|string|in:male,female',
            'password' => [
                'required',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()])[A-Za-z\d@$!%*?&+#^~`|\\/:";\'<>,.=\-_\[\]{}()]+$/',
            ],
            'documentoFrente' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
            'documentoVerso' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
            'selfieDocumento' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:5120',
            'ref' => 'nullable|string|max:50', // Código de referência de afiliado
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'O campo nome de usuário aceita apenas letras, números, espaços, apóstrofos e hífens.',
            'username.unique' => 'Este nome de usuário já está em uso.',
            'name.regex' => 'O nome deve conter apenas letras, espaços, apóstrofos e hífens.',
            'email.unique' => 'Este email já está em uso.',
            'telefone.unique' => 'Este telefone já está em uso.',
            'cpf_cnpj.unique' => 'Este CPF/CNPJ já está em uso.',
            'cpf_cnpj.required' => 'O CPF/CNPJ é obrigatório.',
            'gender.required' => 'O gênero é obrigatório.',
            'gender.in' => 'Gênero inválido. Selecione masculino ou feminino.',
            'password.regex' => 'A senha deve conter pelo menos uma letra minúscula, uma letra maiúscula, um número e um caractere especial.',
            'documentoFrente.mimes' => 'O documento deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
            'documentoFrente.max' => 'O documento não pode exceder 5MB.',
            'documentoVerso.mimes' => 'O documento deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
            'documentoVerso.max' => 'O documento não pode exceder 5MB.',
            'selfieDocumento.mimes' => 'A selfie deve ser uma imagem (JPEG, JPG, PNG) ou PDF.',
            'selfieDocumento.max' => 'A selfie não pode exceder 5MB.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 400)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        );
    }
}
