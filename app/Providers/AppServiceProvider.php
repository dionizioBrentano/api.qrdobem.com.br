<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

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
        // Rate limiting global da API: 60 requests/minuto por IP
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Rate limiting para OTP: 5 requests/minuto por IP (anti-brute-force)
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiting para mensagens públicas: 10/minuto por IP
        RateLimiter::for('public-messages', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
