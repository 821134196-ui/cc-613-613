<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardRequest;
use App\Models\Student;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * 教务管理端（模拟）：登录 users 表中的教务账号，
 * 颁发带 admin ability 的 Sanctum 令牌。
 */
class AdminController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = \App\Models\User::where('email', $data['email'])->first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return response()->json(['message' => '账号或密码不正确。'], 401);
        }

        $token = $admin->createToken('教务端', ['admin'])->plainTextToken;

        return response()->json(['token' => $token, 'admin' => ['name' => $admin->name, 'email' => $admin->email]]);
    }

    /** 受理（办结）一笔补证申请：旧证号永久失效，签发新证。 */
    public function approveReissue(Request $request, CardService $cards, int $requestId): JsonResponse
    {
        $cardRequest = CardRequest::findOrFail($requestId);
        $newCard = $cards->approveReissue($cardRequest);

        return response()->json([
            'message' => '补证已办结，旧证号已永久失效。',
            'new_card' => [
                'id' => $newCard->id,
                'card_no' => $newCard->card_no,
                'version' => $newCard->version,
                'qr_token' => $newCard->qr_token,
            ],
        ]);
    }

    public function rejectReissue(Request $request, CardService $cards, int $requestId): JsonResponse
    {
        $data = $request->validate(['reject_reason' => ['nullable', 'string', 'max:200']]);
        $cards->rejectReissue(CardRequest::findOrFail($requestId), $data['reject_reason'] ?? null);
        return response()->json(['message' => '补证申请已驳回，旧证仍为挂失失效状态。']);
    }

    /** 列出全部学生及学籍状态（教务维护用）。 */
    public function listStudents(Request $request): JsonResponse
    {
        $students = Student::withCount(['cards as active_cards' => function ($q) {
            $q->where('status', \App\Models\StudentCard::STATUS_ACTIVE);
        }])->get()->map(fn (Student $s) => [
            'id' => $s->id,
            'student_no' => $s->student_no,
            'name' => $s->name,
            'college' => $s->college,
            'enrollment_status' => $s->enrollment_status,
            'active_cards' => $s->active_cards,
        ]);

        return response()->json(['students' => $students]);
    }

    /** 列出办理中的申请。 */
    public function listRequests(Request $request): JsonResponse
    {
        $requests = CardRequest::with(['student:id,student_no,name,college,enrollment_status', 'card:id,card_no,status'])
            ->latest('id')->get();

        return response()->json(['requests' => $requests->map(function (CardRequest $r) {
            return [
                'id' => $r->id,
                'type' => $r->type,
                'status' => $r->status,
                'reason' => $r->reason,
                'contact_phone' => $r->contact_phone,
                'reject_reason' => $r->reject_reason,
                'created_at' => $r->created_at?->toDateTimeString(),
                'handled_at' => $r->handled_at?->toDateTimeString(),
                'student' => $r->student ? [
                    'id' => $r->student->id,
                    'student_no' => $r->student->student_no,
                    'name' => $r->student->name,
                    'college' => $r->student->college,
                    'enrollment_status' => $r->student->enrollment_status,
                ] : null,
                'card' => $r->card ? ['id' => $r->card->id, 'card_no' => $r->card->card_no, 'status' => $r->card->status] : null,
            ];
        })]);
    }

    /** 设置学籍状态（休学/在读/毕业）。恢复在读不会恢复已挂失旧证。 */
    public function setEnrollment(Request $request, CardService $cards, int $studentId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,graduated'],
        ]);

        $student = Student::findOrFail($studentId);

        $student = match ($data['status']) {
            Student::STATUS_ACTIVE => $cards->reactivateEnrollment($student),
            Student::STATUS_SUSPENDED => $cards->suspendEnrollment($student),
            default => tap($student, fn ($s) => $s->update(['enrollment_status' => Student::STATUS_GRADUATED])),
        };

        return response()->json([
            'message' => '学籍状态已更新（证件实体状态不受影响）。',
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'enrollment_status' => $student->enrollment_status,
            ],
        ]);
    }
}
