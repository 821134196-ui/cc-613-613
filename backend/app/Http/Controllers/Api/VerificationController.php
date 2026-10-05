<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentCard;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 窗口核验端。扫码得到的是二维码令牌（qr_token），不是学号。
 * 该端点不要求学生登录：窗口工作人员拿到令牌即可核验，
 * 但只能获得最小化的核验结论，无法反查完整学籍。
 */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verification)
    {
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_token' => ['required', 'string', 'size:36'],
        ]);

        $result = $this->verification->verifyByToken(trim($data['qr_token']));

        // 结论本身仍是 200（令牌格式合法），allowed 字段才是放行依据。
        return response()->json($result);
    }

    /**
     * 【本地模拟扫码服务】现实中 qr_token 只能从学生证上的二维码图像解码得到；
     * 本地没有真实扫码枪，这里提供一个“可扫目标”列表供窗口端模拟扫码。
     * 仅返回模拟扫码所需的最小信息（令牌 + 脱敏展示标签）。
     */
    public function simulatorTargets(): JsonResponse
    {
        $cards = StudentCard::with('student:id,name,college,enrollment_status')
            ->orderByDesc('version')
            ->get()
            ->map(function (StudentCard $c) {
                return [
                    'qr_token' => $c->qr_token,
                    'label' => sprintf(
                        '%s（%s · 第%d版 · %s）',
                        $c->student?->name ?? '未知',
                        $c->student?->college ?? '-',
                        $c->version,
                        ['active' => '有效', 'lost' => '已挂失', 'replaced' => '已被替代'][$c->status] ?? $c->status
                    ),
                    'status' => $c->status,
                ];
            });

        return response()->json(['targets' => $cards]);
    }
}
