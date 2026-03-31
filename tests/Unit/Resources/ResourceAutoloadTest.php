<?php

namespace Tests\Unit\Resources;

use Tests\TestCase;

class ResourceAutoloadTest extends TestCase
{
    public function test_device_resource_classes_are_autoloadable(): void
    {
        $this->assertTrue(class_exists(\App\Http\Resources\DeviceResource::class));
        $this->assertTrue(class_exists(\App\Http\Resources\Api\V1\DeviceResource::class));
    }

    public function test_user_resource_classes_are_autoloadable(): void
    {
        $this->assertTrue(class_exists(\App\Http\Resources\UserResource::class));
        $this->assertTrue(class_exists(\App\Http\Resources\Api\V1\UserResource::class));
    }
}
