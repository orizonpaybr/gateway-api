<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource para formatar resposta de Nível
 * 
 * Padroniza:
 * - Formato de dados
 * - Conversão de tipos
 * - Campos calculados
 * 
 * @package App\Http\Resources
 */
class NivelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'cor' => $this->cor,
            'icone' => $this->icone,
            'minimo' => (float) $this->minimo,
            'maximo' => (float) $this->maximo,
            
            // Campos calculados
            'intervalo_formatado' => $this->getIntervaloFormatado(),
            'amplitude' => $this->getAmplitude(),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Retorna o intervalo formatado como string
     *
     * @return string
     */
    protected function getIntervaloFormatado(): string
    {
        return 'R$ ' . number_format($this->minimo, 2, ',', '.') . 
               ' - R$ ' . number_format($this->maximo, 2, ',', '.');
    }

    /**
     * Retorna a amplitude do intervalo
     *
     * @return float
     */
    protected function getAmplitude(): float
    {
        return (float) ($this->maximo - $this->minimo);
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'timestamp' => now()->toISOString(),
            ],
        ];
    }
}

