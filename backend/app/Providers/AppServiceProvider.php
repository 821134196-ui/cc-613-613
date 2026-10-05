<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 登录限流：按账号 + IP，防止撞库
        RateLimiter::for('login', function (Request $request) {
            $account = (string) ($request->input('student_number') ?? $request->input('email') ?? '');

            return Limit::perMinute(10)->by(mb_strtolower($account).'|'.$request->ip());
        });

        // 通用 API 限流：已认证按用户，未认证按 IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user('sanctum')?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
