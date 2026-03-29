<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Services\Auth\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 * 设备管理控制器
 *
 * 用户可查看当前所有登录设备，并主动踢掉指定设备。
 * 所有接口需要 JWT 认证。
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
    ) {}

    /**
     * 获取当前用户的所有在线设备
     *
     * GET /auth/devices
     * Headers: Authorization: Bearer {jwt}
     *
     * 返回设备列表，标记当前设备。
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('jwt_user_id');
        $devices = $this->deviceService->list($userId);

        $data = DeviceResource::collection($devices)->resolve();

        return $this->success($data);
    }

    /**
     * 踢掉指定平台的设备
     *
     * DELETE /auth/devices/{platform}
     * Headers: Authorization: Bearer {jwt}
     *
     * 将目标设备的 JWT 加入黑名单，删除其 Refresh Token。
     * 该设备下次请求时会收到 401 (40104)。
     */
    public function destroy(Request $request, string $platform): JsonResponse
    {
        $userId = $request->attributes->get('jwt_user_id');
        $validPlatforms = ['app', 'mini_program', 'h5', 'pc'];

        if (!in_array($platform, $validPlatforms, true)) {
            return $this->fail(40001, '无效的平台标识', 400);
        }

        // 不允许踢掉自己当前登录的平台
        $currentPlatform = $request->attributes->get('jwt_platform');
        if ($platform === $currentPlatform) {
            return $this->fail(40002, '不能下线当前设备，请使用登出功能', 400);
        }

        $this->deviceService->kick($userId, $platform);

        return $this->success(null, '设备已下线');
    }
}
