<?php

namespace App\Policies;

use App\Models\ReplacementApplication;
use App\Models\Student;

/**
 * 补证申请 / 办理进度仅申请人本人可见。
 * 跨学生访问任何申请 id 一律拒绝（控制器层表现为 403/404）。
 */
class ReplacementApplicationPolicy
{
    public function view(Student $student, ReplacementApplication $application): bool
    {
        return $application->student_id === $student->id;
    }
}
