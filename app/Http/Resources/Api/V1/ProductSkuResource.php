<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSkuResource extends JsonResource
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
            'product_id' => $this->product_id,
            'title' => $this->title,
            'attributes' => $this->attributes,
            'price' => $this->price,
            'original_price' => $this->original_price,
            'stock' => $this->stock,
            'weight' => $this->weight,
            'sku_code' => $this->sku_code,
            'image' => $this->image,
            'sort_order' => $this->sort_order,
        ];
    }
}
