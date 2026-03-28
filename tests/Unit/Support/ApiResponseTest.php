<?php

namespace Tests\Unit\Support;

use Tests\TestCase;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ApiResponseTest extends TestCase
{
    private $responseHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responseHelper = new class {
            use ApiResponse {
                success as public;
                fail as public;
                paginated as public;
            }
        };
    }

    public function test_success_returns_correct_structure(): void
    {
        $response = $this->responseHelper->success(['id' => 1, 'name' => 'test']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertEquals(0, $data['code']);
        $this->assertEquals('success', $data['message']);
        $this->assertEquals(['id' => 1, 'name' => 'test'], $data['data']);
    }

    public function test_success_with_custom_message(): void
    {
        $response = $this->responseHelper->success(null, '创建成功', 201);

        $this->assertEquals(201, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertEquals(0, $data['code']);
        $this->assertEquals('创建成功', $data['message']);
        $this->assertNull($data['data']);
    }

    public function test_success_with_null_data(): void
    {
        $response = $this->responseHelper->success();

        $data = $response->getData(true);
        $this->assertEquals(0, $data['code']);
        $this->assertNull($data['data']);
    }

    public function test_fail_returns_correct_structure(): void
    {
        $response = $this->responseHelper->fail(40101, '账号或密码错误', 401);

        $this->assertEquals(401, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertEquals(40101, $data['code']);
        $this->assertEquals('账号或密码错误', $data['message']);
        $this->assertNull($data['data']);
    }

    public function test_fail_with_extra_data(): void
    {
        $response = $this->responseHelper->fail(49900, '参数验证失败', 422, ['errors' => ['phone' => ['手机号格式不正确']]]);

        $data = $response->getData(true);
        $this->assertEquals(49900, $data['code']);
        $this->assertArrayHasKey('errors', $data['data']);
    }

    public function test_paginated_returns_items_and_pagination(): void
    {
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            items: [['id' => 1], ['id' => 2]],
            total: 50,
            perPage: 20,
            currentPage: 1,
        );

        $response = $this->responseHelper->paginated($paginator);

        $data = $response->getData(true);
        $this->assertEquals(0, $data['code']);
        $this->assertCount(2, $data['data']['items']);
        $this->assertEquals(50, $data['data']['pagination']['total']);
        $this->assertEquals(20, $data['data']['pagination']['per_page']);
        $this->assertEquals(1, $data['data']['pagination']['current_page']);
        $this->assertEquals(3, $data['data']['pagination']['last_page']);
    }
}
