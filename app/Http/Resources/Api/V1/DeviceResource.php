<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // $this is an array because DeviceService->list() returns an array
        // Check if resource is an array or an object
        $isCurrent = is_array($this->resource) ? ($this['is_current'] ?? false) : $this->is_current;
        $platform = is_array($this->resource) ? $this['platform'] : $this->platform;
        $deviceName = is_array($this->resource) ? $this['device_name'] : $this->device_name;
        $clientIp = is_array($this->resource) ? $this['client_ip'] : $this->client_ip;
        $lastActiveAt = is_array($this->resource) ? $this['last_active_at'] : $this->last_active_at;
        $expiresAt = is_array($this->resource) ? $this['expires_at'] : $this->expires_at;

        return [
            'platform'       => $platform,
            'device_name'    => $deviceName,
            'client_ip'      => $clientIp,
            'last_active_at' => is_string($lastActiveAt) ? $lastActiveAt : (clone $lastActiveAt)->toISOString(),
            'expires_at'     => is_string($expiresAt) ? $expiresAt : (clone $expiresAt)->toISOString(),
            'is_current'     => $isCurrent,
        ];
    }
}
