<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\StudentCard;

/**
 * 学生证仅本人可管理（挂失等）。
 */
class StudentCardPolicy
{
    public function manage(Student $student, StudentCard $card): bool
    {
        return $card->student_id === $student->id;
    }
}
