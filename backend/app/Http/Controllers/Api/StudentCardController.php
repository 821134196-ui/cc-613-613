<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentCard;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentCardController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    /**
     * 我的证件列表（仅本人）。
     * 学生只能看到自己名下的证件，包括已挂失 / 已失效的历史证。
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->user();

        $cards = $student->cards()
            ->orderByDesc('sequence')
            ->get()
            ->map(fn (StudentCard $card) => $this->present($card));

        return response()->json(['data' => $cards]);
    }

    /**
     * 挂失。路由绑定的卡片必须属于当前学生（ScopeBindings + 策略），
     * 不能替别人挂失。
     */
    public function reportLost(Request $request, StudentCard $card): JsonResponse
    {
        $this->authorize('manage', $card);

        $updated = $this->cards->reportLost($card);

        return response()->json([
            'message' => $updated->wasChanged('status')
                ? '已挂失，该证即刻起不可使用。'
                : '该证此前已处于失效状态，无需重复挂失。',
            'data' => $this->present($updated),
        ]);
    }

    private function present(StudentCard $card): array
    {
        return [
            'id' => $card->id,
            'card_no' => $card->card_no,
            'status' => $card->status,
            'sequence' => $card->sequence,
            'is_active' => $card->isActive(),
            'issued_at' => $card->issued_at?->toDateTimeString(),
            'lost_at' => $card->lost_at?->toDateTimeString(),
        ];
    }
}
