<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * 学籍（在读资格）管理。
 *
 * 关键不变量：休学 / 恢复只改 students.enrollment_status，
 * 完全不触碰 student_cards —— 恢复在读不会恢复任何已挂失 / 已失效的旧证。
 */
class EnrollmentService
{
    public function suspend(Student $student): Student
    {
        return DB::transaction(function () use ($student) {
            /** @var Student $fresh */
            $fresh = Student::query()->whereKey($student->id)->firstOrFail();

            if ($fresh->enrollment_status !== Student::SUSPENDED) {
                $fresh->update([
                    'enrollment_status' => Student::SUSPENDED,
                    'suspended_at' => now(),
                ]);
            }

            return $fresh;
        });
    }

    public function resume(Student $student): Student
    {
        return DB::transaction(function () use ($student) {
            /** @var Student $fresh */
            $fresh = Student::query()->whereKey($student->id)->firstOrFail();

            // 只恢复“在读资格”本身。此处刻意不更新 student_cards 任何字段：
            // lost / replaced 终态由证件实体自己负责，永不随学籍恢复。
            if ($fresh->enrollment_status !== Student::ENROLLED) {
                $fresh->update([
                    'enrollment_status' => Student::ENROLLED,
                    'suspended_at' => null,
                ]);
            }

            return $fresh;
        });
    }
}
