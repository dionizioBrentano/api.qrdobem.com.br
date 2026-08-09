<?php

$url = 'http://localhost:8000/api/donation-causes';

$payload = [
    'amount' => 50,
    'payment_method' => 'pix',
    'payer_name' => 'Teste Guest',
    'payer_email' => 'guest@teste.com',
    'payer_cpf' => '00000000000',
    'consent_lgpd' => true
];

$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n" .
                     "Accept: application/json\r\n",
        'content' => json_encode($payload),
        'ignore_errors' => true
    ]
];

$context  = stream_context_create($options);
$response = file_get_contents($url, false, $context);
$http_response_header = $http_response_header ?? [];
$status_line = $http_response_header[0] ?? '';

echo "Status: $status_line\n";
echo "Response: $response\n";
