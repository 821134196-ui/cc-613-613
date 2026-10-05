<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Models\StudentCard;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReplacementApplicationController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    /**
     * 提交补证申请。
     * 只允许对本人名下、且已不在有效状态（通常为已挂失）的证申请补证。
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $data = $request->validate([
            'lost_card_id' => ['required', 'integer', Rule::exists('student_cards', 'id')],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var StudentCard $card 用本人作用域查询，跨学生传 id 直接 404 */
        $card = $student->cards()->findOrFail($data['lost_card_id']);

        if ($card->status === StudentCard::REPLACED) {
            return response()->json([
                'message' => '该证已补领新证，旧证号已永久失效，不能再次申请补证。',
            ], 422);
        }

        // 挂失与补证可一并完成：申请补证时若证仍有效，先在服务端挂失
        if ($card->isActive()) {
            $card = $this->cards->reportLost($card);
        }

        // 同一失证存在未完结的补证申请时不重复受理
        $existing = ReplacementApplication::query()
            ->where('student_id', $student->id)
            ->where('lost_card_id', $card->id)
            ->whereNotIn('status', [ReplacementApplication::COMPLETED, ReplacementApplication::REJECTED])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => '该证已有进行中的补证申请，请勿重复提交。',
                'data' => $this->present($existing),
            ], 409);
        }

        $application = ReplacementApplication::create([
            'student_id' => $student->id,
            'lost_card_id' => $card->id,
            'status' => ReplacementApplication::PENDING,
            'reason' => $data['reason'] ?? '证件遗失',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => '补证申请已提交，可在“办理进度”中查询。',
            'data' => $this->present($application),
        ], 201);
    }

    /**
     * 我的补证申请列表（仅本人）。
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $applications = ReplacementApplication::query()
            ->where('student_id', $student->id)
            ->with(['lostCard', 'newCard'])
            ->latest('submitted_at')
            ->get()
            ->map(fn (ReplacementApplication $a) => $this->present($a));

        return response()->json(['data' => $applications]);
    }

    /**
     * 查询单笔申请进度 —— 路由模型绑定时限定本人，跨学生访问返回 404。
     */
    public function show(Request $request, ReplacementApplication $application): JsonResponse
    {
        $this->authorize('view', $application);

        $application->load(['lostCard', 'newCard']);

        return response()->json(['data' => $this->present($application)]);
    }

    private function present(ReplacementApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'reason' => $application->reason,
            'submitted_at' => $application->submitted_at?->toDateTimeString(),
            'completed_at' => $application->completed_at?->toDateTimeString(),
            'rejected_at' => $application->rejected_at?->toDateTimeString(),
            'lost_card_no' => $application->lostCard?->card_no,
            'lost_card_status' => $application->lostCard?->status,
            'new_card_no' => $application->newCard?->card_no,
        ];
    }
}
