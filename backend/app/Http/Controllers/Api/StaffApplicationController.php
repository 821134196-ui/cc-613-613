<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReplacementApplication;
use App\Models\Student;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 窗口 / 学籍管理端（users，staff 角色）。
 *
 * 受理补证申请：完成时在 CardService 单一事务内终结旧证、签发新证，
 * 保证“补证完成后旧证号永久失效”。
 */
class StaffApplicationController extends Controller
{
    public function __construct(private readonly CardService $cards)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();

        $applications = ReplacementApplication::query()
            ->with(['student', 'lostCard'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->oldest('submitted_at')
            ->get()
            ->map(fn (ReplacementApplication $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'reason' => $a->reason,
                'submitted_at' => $a->submitted_at?->toDateTimeString(),
                'student' => [
                    'id' => $a->student->id,
                    'name' => $a->student->name,
                    'student_number_masked' => \App\Support\CardNumber::maskStudentNumber($a->student->student_number),
                    'enrollment_status' => $a->student->enrollment_status,
                ],
                'lost_card_no' => $a->lostCard?->card_no,
                'lost_card_status' => $a->lostCard?->status,
            ]);

        return response()->json(['data' => $applications]);
    }

    /** 受理：pending → processing */
    public function markProcessing(ReplacementApplication $application): JsonResponse
    {
        if ($application->isFinished()) {
            return response()->json(['message' => '申请已完结，状态不可再变更。'], 422);
        }

        $application->update(['status' => ReplacementApplication::PROCESSING]);

        return response()->json(['message' => '已开始制证。', 'data' => ['status' => $application->status]]);
    }

    /**
     * 完成补证：终结旧证 + 签发新证，同一事务原子提交。
     * 完成后学生看到新证 active，旧证号 replaced 永久失效。
     */
    public function complete(Request $request, ReplacementApplication $application): JsonResponse
    {
        if ($application->status === ReplacementApplication::COMPLETED) {
            return response()->json(['message' => '该申请已完成补证，不能重复发放。'], 422);
        }
        if ($application->status === ReplacementApplication::REJECTED) {
            return response()->json(['message' => '该申请已驳回，不能完成。'], 422);
        }

        $application->loadMissing(['student', 'lostCard']);

        $newCard = DB::transaction(function () use ($request, $application) {
            // 原子认领：只在申请仍处于未完结状态时推进到 processing，
            // 并发重复完成时第二个请求 affected=0，得到 422 而不会重复发证。
            $claimed = DB::table('replacement_applications')
                ->where('id', $application->id)
                ->whereNotIn('status', [ReplacementApplication::COMPLETED, ReplacementApplication::REJECTED])
                ->update(['status' => ReplacementApplication::PROCESSING, 'updated_at' => now()]);

            if ($claimed === 0) {
                abort(422, '该申请已完结，不能重复发放新证。');
            }

            /** @var ReplacementApplication $locked */
            $locked = ReplacementApplication::query()->whereKey($application->id)->firstOrFail();
            $locked->loadMissing(['student', 'lostCard']);

            return $this->cards->issueReplacement(
                $locked->student,
                $locked->lostCard,
                $request->user()->id,
                $locked->id,
            );
        });

        return response()->json([
            'message' => '补证已完成，旧证号即刻永久失效，新证已签发。',
            'data' => [
                'application_id' => $application->id,
                'status' => ReplacementApplication::COMPLETED,
                'new_card_no' => $newCard->card_no,
            ],
        ]);
    }

    public function reject(ReplacementApplication $application): JsonResponse
    {
        if ($application->isFinished()) {
            return response()->json(['message' => '申请已完结，状态不可再变更。'], 422);
        }

        $application->update([
            'status' => ReplacementApplication::REJECTED,
            'rejected_at' => now(),
        ]);

        return response()->json(['message' => '已驳回。', 'data' => ['status' => $application->status]]);
    }
}
