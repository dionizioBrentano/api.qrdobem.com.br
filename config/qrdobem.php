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
    | URL do frontend
    |--------------------------------------------------------------------------
    |
    | Base para os links enviados por e-mail (cadastro, aviso de mensagem nova).
    | Precisa ficar aqui, e não num env() dentro de controller: com a config
    | cacheada em produção, env() devolve null e o link sai com o valor padrão.
    |
    */

    'frontend_url' => rtrim(env('FRONTEND_URL', 'https://qrdobem.com.br'), '/'),

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

    /*
    |--------------------------------------------------------------------------
    | Doações — taxa operacional da OSCIP/plataforma
    |--------------------------------------------------------------------------
    |
    | Única modalidade fiscal ATIVA: doação com recibo emitido pela OSCIP
    | gestora do QR do Bem. NÃO há projeto de lei de incentivo homologado
    | (nada de PRONON/Rouanet/FIA como benefício ativo) — logo, não existe
    | dedução de IRPF a exibir. O que existe é esta taxa.
    |
    | `platform_fee_percent` é a taxa operacional cobrada sobre o VALOR BRUTO
    | da doação. Fica em config (e não hardcoded no serviço de cálculo) para
    | que o admin possa ajustar sem tocar em código. O custo do meio de
    | pagamento é OUTRA coisa: é custo real, à parte, discriminado por doação,
    | e não sai daqui — ver App\Services\DonationFeeCalculator.
    |
    | Precisa ficar em config PHP, e não num env() dentro do serviço: com a
    | config cacheada em produção, env() devolve null e a taxa zeraria.
    |
    */

    'donation' => [
        'platform_fee_percent' => (float) env('DONATION_PLATFORM_FEE_PERCENT', 12),
    ],

    'otp_minutes' => (int) env('QR_OTP_MINUTES', 15),

    'read_dedup_minutes' => (int) env('QR_READ_DEDUP_MINUTES', 15),

    'composite' => [
        'background' => env('QR_COMPOSITE_BG', '#ffffff'),
        'caption_color' => env('QR_COMPOSITE_CAPTION', '#000000'),
        'brand_color' => env('QR_COMPOSITE_BRAND', '#444444'),
        'brand_label' => env('QR_COMPOSITE_BRAND_LABEL', 'QR do Bem'),
        'caption_offset_y' => 35,
        'qr_offset_y' => 50,
        'extra_height' => 100,
        'footer_inset' => 15,
    ],

    'print_batch' => [
        'meta_color' => env('QR_BATCH_META_COLOR', '#555'),
        'cut_color' => env('QR_BATCH_CUT_COLOR', '#bbb'),
        'code_color' => env('QR_BATCH_CODE_COLOR', '#333'),
        'num_color' => env('QR_BATCH_NUM_COLOR', '#999'),
    ],

];
