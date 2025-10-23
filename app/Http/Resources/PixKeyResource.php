<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para formatação consistente de chaves PIX
 */
class PixKeyResource extends JsonResource
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
            'key_type' => $this->key_type,
            'key_type_label' => $this->getKeyTypeLabel(),
            'key_value' => $this->key_value,
            'key_value_formatted' => $this->formatted_key,
            'key_label' => $this->key_label,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'icon' => $this->key_icon,
            'verified_at' => $this->verified_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}

