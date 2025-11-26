<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Collection Resource para lista de níveis
 * 
 * Adiciona metadados e estatísticas à coleção
 * 
 * @package App\Http\Resources
 */
class NivelCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = NivelResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'niveis' => $this->collection,
            'estatisticas' => $this->getEstatisticas(),
        ];
    }

    /**
     * Calcula estatísticas da coleção de níveis
     *
     * @return array<string, mixed>
     */
    protected function getEstatisticas(): array
    {
        $niveis = $this->collection;

        return [
            'total' => $niveis->count(),
            'valor_minimo_total' => $niveis->min('minimo'),
            'valor_maximo_total' => $niveis->max('maximo'),
            'amplitude_total' => $niveis->sum(function ($nivel) {
                return $nivel->maximo - $nivel->minimo;
            }),
        ];
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

