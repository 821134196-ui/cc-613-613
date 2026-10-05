<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 生产模式托管 React 构建产物（resources 输出到 public/app）。
| 开发模式由 Vite (5173) 提供页面并代理 /api 到本服务。
|--------------------------------------------------------------------------
*/
Route::get('/app/{any?}', function () {
    $path = public_path('app/index.html');
    abort_unless(file_exists($path), 404);

    return response()->file($path);
})->where('any', '.*');

Route::get('/', function () {
    // 若已构建前端，则根路径直接进入应用
    $built = public_path('app/index.html');
    if (file_exists($built)) {
        return response()->file($built);
    }

    return response()->json([
        'name' => '高校学生证优惠资格核验与挂失系统 API',
        'status' => 'running',
        'frontend_dev' => 'http://127.0.0.1:5173',
    ]);
});
