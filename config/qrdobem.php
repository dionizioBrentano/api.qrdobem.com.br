<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL pública do QR Code
    |--------------------------------------------------------------------------
    |
    | A API é consumida por vários frontends (whitelabel). A URL gravada dentro
    | do QR Code é montada aqui, e não espalhada pelos controllers.
    |
    | Exemplo final: https://qrdobem.com.br/q/9b1f...-uuid
    |
    */

    'public_base_url' => rtrim(env('QR_PUBLIC_BASE_URL', 'https://qrdobem.com.br'), '/'),

    'public_path_prefix' => trim(env('QR_PUBLIC_PATH_PREFIX', 'q'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Aparência do QR Code
    |--------------------------------------------------------------------------
    |
    | size  → lado do quadrado em pixels (o SVG é vetorial, isso define o
    |         viewBox; ampliar depois não perde qualidade).
    | margin → quiet zone em módulos. O padrão ISO é 4; abaixo disso alguns
    |          leitores falham.
    | error_correction → L (7%), M (15%), Q (25%), H (30%). Usamos Q porque
    |          a etiqueta pode ser colada em coleira, pulseira ou objeto e
    |          sofrer desgaste.
    |
    */

    'size' => (int) env('QR_SIZE', 512),

    'margin' => (int) env('QR_MARGIN', 4),

    'error_correction' => env('QR_ERROR_CORRECTION', 'Q'),

    /*
    |--------------------------------------------------------------------------
    | Créditos de Onboarding
    |--------------------------------------------------------------------------
    |
    | Quantidade de créditos concedida automaticamente quando o usuário
    | conclui o Gate 1 de cadastro (profile_status vira 'active').
    |
    */

    'onboarding_credits' => (int) env('QR_ONBOARDING_CREDITS', 3),

    'onboarding_credits_days' => (int) env('QR_ONBOARDING_DAYS', 30),

];
