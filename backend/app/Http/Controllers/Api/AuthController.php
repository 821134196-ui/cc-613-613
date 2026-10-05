<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** 学生端登录（学号 + 密码），返回仅用于学生本人接口的 token */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_number' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $student = Student::query()->where('student_number', $data['student_number'])->first();

        if (!$student || !Hash::check($data['password'], $student->password)) {
            throw ValidationException::withMessages([
                'student_number' => ['学号或密码不正确。'],
            ]);
        }

        $token = $student->createToken('student-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'student' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->name,
                'college' => $student->college,
                'enrollment_status' => $student->enrollment_status,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '已退出登录。']);
    }

    public function me(Request $request): JsonResponse
    {
        $student = $request->user();

        return response()->json([
            'id' => $student->id,
            'student_number' => $student->student_number,
            'name' => $student->name,
            'college' => $student->college,
            'enrollment_status' => $student->enrollment_status,
        ]);
    }
}
