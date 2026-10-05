<?php

namespace App\Http\Middleware;

use App\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仅允许学生身份（App\Models\Student 的令牌）访问。
 * 教务令牌访问学生接口时直接 403，防止身份串用。
 */
class EnsureStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof Student) {
            return response()->json(['message' => '该接口仅限学生本人访问。'], 403);
        }

        return $next($request);
    }
}
