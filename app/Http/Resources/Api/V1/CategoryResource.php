<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_enabled' => $this->is_enabled,
            'level' => $this->level,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
