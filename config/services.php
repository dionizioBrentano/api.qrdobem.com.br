<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Meta Cloud API)
    |--------------------------------------------------------------------------
    |
    | Canal do Botão de Pânico (T1-R07). Ver App\Services\Notification\
    | WhatsAppChannel.
    |
    | Enquanto `phone_number_id` e `access_token` estiverem vazios, o canal
    | se declara indisponível e o NotificationDispatcher cai para o e-mail
    | sozinho — é o que faz o sistema funcionar HOJE, antes da aprovação da
    | Meta, sem precisar mexer no código depois.
    |
    | ATENÇÃO: mensagem iniciada pelo sistema fora da janela de 24 horas só
    | trafega por TEMPLATE aprovado pela Meta. O template do pânico se chama
    | `panic_alert` e precisa ser cadastrado e aprovado no painel antes de
    | qualquer disparo real.
    |
    */
    'whatsapp' => [
        'graph_base'        => env('WHATSAPP_GRAPH_BASE', 'https://graph.facebook.com'),
        'phone_number_id'   => env('WHATSAPP_PHONE_NUMBER_ID', ''),
        'access_token'      => env('WHATSAPP_ACCESS_TOKEN', ''),
        'api_version'       => env('WHATSAPP_API_VERSION', 'v21.0'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR'),
    ],

];
