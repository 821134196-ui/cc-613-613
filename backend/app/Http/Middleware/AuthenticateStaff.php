<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅允许窗口 / 管理人员 token（users 表）访问管理端接口。
 * 学生 token 不能用于窗口受理、学籍变更或扫码核验。
 */
class AuthenticateStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (!$user instanceof User) {
            return response()->json(['message' => '请使用窗口工作人员账号登录。'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
