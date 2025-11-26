<?php

namespace App\Http\Requests;

use App\Models\Nivel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request para atualização de níveis de gamificação
 * 
 * Similar ao StoreNivelRequest mas:
 * - Permite atualização parcial (sometimes)
 * - Ignora o próprio nível na validação de unique e overlap
 * 
 * @package App\Http\Requests
 */
class UpdateNivelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->permission === 3;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $nivelId = $this->route('id');

        return [
            'nome' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('niveis', 'nome')->ignore($nivelId),
            ],
            'cor' => 'sometimes|nullable|string|max:50',
            'minimo' => 'sometimes|required|numeric|min:0',
            'maximo' => 'sometimes|required|numeric|min:0',
            'icone' => 'sometimes|nullable|string|max:255',
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
            'nome.unique' => 'Já existe um nível com este nome.',
            'nome.max' => 'O nome do nível não pode ter mais de 100 caracteres.',
            'minimo.min' => 'O valor mínimo deve ser maior ou igual a zero.',
            'maximo.min' => 'O valor máximo deve ser maior ou igual a zero.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validar que máximo > mínimo
            $nivel = Nivel::find($this->route('id'));
            if (!$nivel) {
                return;
            }

            $minimo = $this->input('minimo', $nivel->minimo);
            $maximo = $this->input('maximo', $nivel->maximo);

            if ($maximo <= $minimo) {
                $validator->errors()->add(
                    'maximo',
                    'O valor máximo deve ser maior que o mínimo.'
                );
            }

            // Verificar sobreposição (excluindo o próprio nível)
            if ($this->hasOverlap($nivel->id, $minimo, $maximo)) {
                $validator->errors()->add(
                    'minimo',
                    'Os valores mínimo e máximo se sobrepõem com outro nível existente.'
                );
            }
        });
    }

    /**
     * Verifica se há sobreposição de intervalos com outros níveis
     *
     * @param int $nivelId ID do nível sendo atualizado
     * @param float $minimo
     * @param float $maximo
     * @return bool
     */
    protected function hasOverlap(int $nivelId, float $minimo, float $maximo): bool
    {
        return Nivel::where('id', '!=', $nivelId)
            ->where(function ($query) use ($minimo, $maximo) {
                $query->whereBetween('minimo', [$minimo, $maximo])
                    ->orWhereBetween('maximo', [$minimo, $maximo])
                    ->orWhere(function ($q) use ($minimo, $maximo) {
                        $q->where('minimo', '<=', $minimo)
                            ->where('maximo', '>=', $maximo);
                    });
            })->exists();
    }
}

