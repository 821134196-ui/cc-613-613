<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 窗口扫码核验。
 *
 * 结论一律由服务端实时计算：接口只接收二维码里的 scan_token，
 * 不接收任何客户端声称的“有效 / 在读”状态，因此窗口无法仅凭
 * 本地页面显示放行。并发场景（核验与挂失同时发生）下，
 * IMMEDIATE 写事务 + WAL 保证服务端串行判断：
 *   - 挂失先提交 → 本次核验看到 lost → 拒绝
 *   - 核验先读到旧状态并返回 → 该放行只代表核验时刻的服务端状态
 */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verification)
    {
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scan_token' => ['required', 'string', 'size:52'],
        ]);

        $result = $this->verification->verifyByToken($data['scan_token']);

        $httpStatus = $result['eligible'] ? 200 : 403;

        return response()->json($result, $httpStatus);
    }
}
