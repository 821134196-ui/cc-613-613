<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\ReplacementApplicationController;
use App\Http\Controllers\Api\ScanSimulationController;
use App\Http\Controllers\Api\StaffApplicationController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffStudentController;
use App\Http\Controllers\Api\StudentCardController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 公开接口（登录）
|--------------------------------------------------------------------------
*/
Route::post('/student/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/staff/auth/login', [StaffAuthController::class, 'login'])->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| 学生端：仅学生本人 token 可访问，所有资源限定在本人名下
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'student.auth', 'throttle:api'])->prefix('student')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // 我的证件 + 挂失
    Route::get('/cards', [StudentCardController::class, 'index']);
    Route::post('/cards/{card}/lost', [StudentCardController::class, 'reportLost']);

    // 补证申请 + 办理进度（仅本人）
    Route::get('/applications', [ReplacementApplicationController::class, 'index']);
    Route::post('/applications', [ReplacementApplicationController::class, 'store']);
    Route::get('/applications/{application}', [ReplacementApplicationController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| 窗口 / 管理端：仅工作人员 token
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'staff.auth', 'throttle:api'])->group(function () {
    // 扫码核验 —— 结论完全由服务端实时计算
    Route::post('/verification/scan', [VerificationController::class, 'scan']);

    // 补证受理
    Route::get('/staff/applications', [StaffApplicationController::class, 'index']);
    Route::post('/staff/applications/{application}/processing', [StaffApplicationController::class, 'markProcessing']);
    Route::post('/staff/applications/{application}/complete', [StaffApplicationController::class, 'complete']);
    Route::post('/staff/applications/{application}/reject', [StaffApplicationController::class, 'reject']);

    // 学籍（资格）变更
    Route::get('/staff/students', [StaffStudentController::class, 'index']);
    Route::post('/staff/students/{student}/suspend', [EnrollmentController::class, 'suspend']);
    Route::post('/staff/students/{student}/resume', [EnrollmentController::class, 'resume']);

    // 本地模拟扫码用的 token 列表（仅演示）
    Route::get('/simulation/cards', [ScanSimulationController::class, 'cards']);
});
