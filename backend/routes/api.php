<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentCardController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 公开接口
|--------------------------------------------------------------------------
*/

// 学生登录 / 教务登录
Route::post('/student/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AdminController::class, 'login']);

/*
 * 窗口扫码核验：无需登录，但只凭二维码令牌实时查库，
 * 返回最小化身份信息 + 是否放行 + 原因。
 */
Route::post('/verify', [VerificationController::class, 'verify'])
    ->middleware('throttle:60,1');

// 【本地模拟扫码服务】返回可扫目标令牌列表，仅本地演示使用。
Route::get('/simulator/targets', [VerificationController::class, 'simulatorTargets']);

/*
|--------------------------------------------------------------------------
| 学生端（本人令牌 + student 身份中间件）
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'student'])->prefix('student')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // 我的证件
    Route::get('/cards', [StudentCardController::class, 'index']);
    // 挂失
    Route::post('/cards/{cardId}/loss', [StudentCardController::class, 'reportLoss']);

    // 补证申请
    Route::post('/reissues', [StudentCardController::class, 'applyReissue']);

    // 我的申请（办理进度）
    Route::get('/requests', [StudentCardController::class, 'requests']);
    Route::get('/requests/{requestId}', [StudentCardController::class, 'showRequest']);
});

/*
|--------------------------------------------------------------------------
| 教务管理端（admin ability）
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'ability:admin'])->prefix('admin')->group(function () {
    Route::get('/students', [AdminController::class, 'listStudents']);
    Route::get('/requests', [AdminController::class, 'listRequests']);
    Route::post('/requests/{requestId}/approve', [AdminController::class, 'approveReissue']);
    Route::post('/requests/{requestId}/reject', [AdminController::class, 'rejectReissue']);
    Route::post('/students/{studentId}/enrollment', [AdminController::class, 'setEnrollment']);
});
