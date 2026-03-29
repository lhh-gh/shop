<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'category_id' => $this->category_id,
            'shipping_template_id' => $this->shipping_template_id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'main_image' => $this->main_image,
            'images' => $this->images,
            'description' => $this->description,
            'base_price' => $this->base_price,
            'sales_count' => $this->sales_count,
            'review_count' => $this->review_count,
            'status' => $this->status,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'skus' => ProductSkuResource::collection($this->whenLoaded('skus')),
            'attributes' => ProductAttributeResource::collection($this->whenLoaded('attributes')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
