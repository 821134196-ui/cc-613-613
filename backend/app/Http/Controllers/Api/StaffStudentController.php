<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffStudentController extends Controller
{
    /** 学生名册（窗口端仅展示掩码学号） */
    public function index(Request $request): JsonResponse
    {
        $students = Student::query()
            ->withCount('cards')
            ->orderBy('student_number')
            ->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'student_number_masked' => CardNumber::maskStudentNumber($s->student_number),
                'college' => $s->college,
                'enrollment_status' => $s->enrollment_status,
                'cards_count' => $s->cards_count,
            ]);

        return response()->json(['data' => $students]);
    }
}
