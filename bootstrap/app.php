<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Registra o nosso middleware do Firebase para proteger as rotas!
        $middleware->alias([
            'auth.firebase' => \App\Http\Middleware\FirebaseAuth::class,

            // Autenticação que NÃO bloqueia (Fase 4, T4-R09).
            // Usada nas rotas públicas da URL do beneficiário: sem token a
            // requisição segue como pública; com token válido, o tenant é
            // preenchido para que a confirmação por TUTOR possa exigir que
            // o tutor esteja identificado.
            'auth.firebase.optional' => \App\Http\Middleware\OptionalFirebaseAuth::class,

            // API pública de parceiros (Fase 5, T3-R01).
            // Aceita o escopo exigido como parâmetro: 'api.key:entities.read'.
            // Assim a rota declara o que precisa, e a verificação não fica
            // espalhada dentro dos controllers.
            'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
        ]);

        // Rate limiting global para API
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
