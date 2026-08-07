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

        // Recuperação de conversa: 5/minuto por IP (anti-brute-force do código de 4 caracteres)
        RateLimiter::for('conversation-recovery', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Criação de doação (rota pública, dispara pagamento): 6/minuto por IP.
        // É um ato deliberado — 6 tentativas por minuto sobra para o doador
        // legítimo e corta script que tenta abrir preferências em massa.
        RateLimiter::for('donation-create', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        // Preview do rateio (só cálculo, sem PII): 30/minuto por IP. Mais
        // folgado porque o front recalcula ao digitar, mas ainda limitado.
        RateLimiter::for('donation-preview', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
