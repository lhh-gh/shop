<?php

namespace App\Http\Resources;

use App\Support\DataMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'phone'    => $this->phone ? DataMasker::phone($this->phone) : null,
            'email'    => $this->email ? DataMasker::email($this->email) : null,
            'nickname' => $this->nickname,
            'avatar'   => $this->avatar,
            'status'   => $this->status,
        ];
    }
}
