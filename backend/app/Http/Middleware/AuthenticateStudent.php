<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅允许学生本人 token（students 表）访问学生端接口。
 */
class AuthenticateStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (!$user instanceof Student) {
            return response()->json(['message' => '请使用学生账号登录。'], 401);
        }

        // 让策略与 $request->user() 始终解析到学生实体
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
