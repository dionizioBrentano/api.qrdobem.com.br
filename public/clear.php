<?php
// Script Salva-Vidas para cPanel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

\Illuminate\Support\Facades\Artisan::call('route:clear');
\Illuminate\Support\Facades\Artisan::call('config:clear');
\Illuminate\Support\Facades\Artisan::call('cache:clear');
\Illuminate\Support\Facades\Artisan::call('view:clear');

echo "<h1>O CACHE DO SERVIDOR FOI ANIQUILADO COM SUCESSO!</h1>";
echo "<p>Agora o Laravel vai ser OBRIGADO a ler o seu arquivo routes/api.php novo.</p>";
