<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardRequest;
use App\Models\StudentCard;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentCardController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    /** 我的证件列表（仅本人）。 */
    public function index(Request $request): JsonResponse
    {
        $cards = $request->user()->cards()->orderByDesc('version')->get();

        return response()->json([
            'cards' => $cards->map(fn (StudentCard $c) => $this->cardPayload($c)),
        ]);
    }

    /**
     * 挂失指定证件。路由参数中的卡片必须属于当前学生，
     * 否则按不存在处理（不泄露他人证件是否存在）。
     */
    public function reportLoss(Request $request, int $cardId): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $card = $this->findOwnedCardOrFail($request->user(), $cardId);

        $req = $this->cards->reportLoss($request->user(), $card, $data['reason'] ?? null);

        return response()->json([
            'message' => '挂失成功，该证件已立即失效。',
            'request' => $this->requestPayload($req->load('card', 'newCard')),
        ]);
    }

    /** 提交补证申请。 */
    public function applyReissue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $req = $this->cards->applyReissue($request->user(), $data);

        return response()->json([
            'message' => '补证申请已提交，请等待受理。',
            'request' => $this->requestPayload($req->load('card', 'newCard')),
        ], 201);
    }

    /** 我的申请列表（含办理进度）。 */
    public function requests(Request $request): JsonResponse
    {
        $requests = $request->user()
            ->requests()
            ->with(['card:id,card_no,status,version', 'newCard:id,card_no,status,version'])
            ->latest('id')
            ->get();

        return response()->json([
            'requests' => $requests->map(fn (CardRequest $r) => $this->requestPayload($r)),
        ]);
    }

    /**
     * 申请详情：强制归属校验，跨学生访问返回 404（不返回他人补证资料）。
     */
    public function showRequest(Request $request, int $requestId): JsonResponse
    {
        $req = $request->user()
            ->requests()
            ->with(['card', 'newCard'])
            ->findOrFail($requestId);

        return response()->json(['request' => $this->requestPayload($req)]);
    }

    private function findOwnedCardOrFail($user, int $cardId): StudentCard
    {
        return StudentCard::where('student_id', $user->id)->findOrFail($cardId);
    }

    private function cardPayload(StudentCard $c): array
    {
        return [
            'id' => $c->id,
            'card_no' => $c->card_no,
            'version' => $c->version,
            'status' => $c->status,
            'is_valid' => $c->isValid(),
            'lost_at' => $c->lost_at?->toDateTimeString(),
            'replaced_at' => $c->replaced_at?->toDateTimeString(),
            // 学生端扫码核验/模拟需要令牌；该接口仅本人可访问。
            'qr_token' => $c->qr_token,
        ];
    }

    private function requestPayload(CardRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'type_label' => $r->type === CardRequest::TYPE_REPORT_LOSS ? '挂失' : '补证',
            'status' => $r->status,
            'status_label' => [
                CardRequest::STATUS_PENDING => '办理中',
                CardRequest::STATUS_APPROVED => '已办结',
                CardRequest::STATUS_REJECTED => '已驳回',
            ][$r->status] ?? $r->status,
            'reason' => $r->reason,
            'contact_phone' => $r->contact_phone,
            'reject_reason' => $r->reject_reason,
            'handled_at' => $r->handled_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'card' => $r->card ? [
                'id' => $r->card->id,
                'card_no' => $r->card->card_no,
                'version' => $r->card->version,
                'status' => $r->card->status,
            ] : null,
            'new_card' => $r->newCard ? [
                'id' => $r->newCard->id,
                'card_no' => $r->newCard->card_no,
                'version' => $r->newCard->version,
                'status' => $r->newCard->status,
            ] : null,
        ];
    }
}
