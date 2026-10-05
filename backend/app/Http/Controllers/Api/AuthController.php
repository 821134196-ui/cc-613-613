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
    /** 学生登录：以学号 + 密码换取 Sanctum 令牌。 */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_no' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        $student = Student::where('student_no', $data['student_no'])->first();

        if (!$student || !Hash::check($data['password'], $student->password)) {
            throw ValidationException::withMessages([
                'student_no' => ['学号或密码不正确。'],
            ]);
        }

        // 学生令牌只授予 student ability，不能访问教务接口（避免 '*' 通配）。
        $token = $student->createToken($data['device_name'] ?? '学生端', ['student'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'student' => $this->profilePayload($student),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => '已退出登录。']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['student' => $this->profilePayload($request->user())]);
    }

    private function profilePayload(Student $student): array
    {
        return [
            'id' => $student->id,
            'student_no' => $student->student_no,
            'name' => $student->name,
            'college' => $student->college,
            'major' => $student->major,
            'enrollment_status' => $student->enrollment_status,
        ];
    }
}
