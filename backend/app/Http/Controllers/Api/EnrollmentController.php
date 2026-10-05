<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;

/**
 * 学籍管理：休学 / 恢复在读。
 * 只变更在读资格，绝不触碰任何学生证状态。
 */
class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollment)
    {
    }

    public function suspend(Student $student): JsonResponse
    {
        $updated = $this->enrollment->suspend($student);

        return response()->json([
            'message' => '已办理休学，在读优惠资格中止（证件状态不变）。',
            'data' => ['enrollment_status' => $updated->enrollment_status],
        ]);
    }

    public function resume(Student $student): JsonResponse
    {
        $updated = $this->enrollment->resume($student);

        return response()->json([
            'message' => '已恢复在读资格（已挂失 / 已失效的旧证不会恢复）。',
            'data' => ['enrollment_status' => $updated->enrollment_status],
        ]);
    }
}
