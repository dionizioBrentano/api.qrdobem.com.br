<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\WebhookController;

// CORS é tratado exclusivamente pelo middleware HandleCors do Laravel (config/cors.php)
// NÃO adicionar headers manuais aqui — isso causa duplicação e bloqueio no browser.

// --- Rotas públicas (sem autenticação) ---
Route::middleware('throttle:otp')->group(function () {
    Route::post('/auth/send-otp', [OtpController::class, 'sendOtp']);
    Route::post('/auth/verify-otp', [OtpController::class, 'verifyOtp']);
    
    Route::post('/auth/register-link', [\App\Http\Controllers\RegisterController::class, 'sendLink']);
    Route::get('/auth/register-validate', [\App\Http\Controllers\RegisterController::class, 'validateToken']);
});

Route::middleware('throttle:public-messages')->group(function () {
    Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'store']);
    Route::post('/waitlist', [\App\Http\Controllers\WaitlistController::class, 'store']);
});

// Webhooks
// Nota sobre CSRF: No Laravel 11, rotas definidas em routes/api.php utilizam
// apenas o middleware group 'api', que por padrão NÃO inclui o VerifyCsrfToken.
Route::get('/webhooks/mercadopago', function () {
    return response()->json(['ok' => true, 'service' => 'mercadopago-webhook']);
});
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago']);

// --- Rotas protegidas (Firebase Auth) ---
Route::middleware('auth.firebase')->group(function () {
    Route::get('/auth/me', [OtpController::class, 'me']);
    Route::post('/auth/register-complete', [\App\Http\Controllers\RegisterController::class, 'complete'])->middleware('throttle:otp');

    // Perfil (progressivo, sem fricção)
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/documents', [ProfileController::class, 'addDocument']);

    Route::get('/entities', [EntityController::class, 'index']);
    Route::post('/entities', [EntityController::class, 'store']);
    // QR Code gerado pela API (whitelabel: nenhum frontend precisa gerar imagem)
    Route::get('/entities/{unique_code}/qrcode', [EntityController::class, 'qrCode']);
    Route::post('/entities/{unique_code}/vaccinations', [EntityController::class, 'addVaccination']);

    // Decifragem do CPF de quem declarou emergência (superadmin, auditado)
    Route::get('/admin/emergency-declarations/{id}/reveal', [EmergencyController::class, 'reveal']);

    Route::get('/admin/tenants', [AdminController::class, 'getTenants']);
    // Rota add-quota removida: o método addQuota nunca existiu no AdminController
    // (resultava em 500). Créditos são concedidos via /admin/batches.
    Route::post('/admin/batches', [AdminController::class, 'createBatch']);
    Route::post('/admin/batches/{id}/toggle', [AdminController::class, 'toggleBatchStatus']);

    // Inbox (Fase 6)
    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages/{id}/read', [MessageController::class, 'markAsRead']);

    // Chat mediado — lado do tutor
    Route::post('/conversations/{conversation_id}/reply', [ConversationController::class, 'tenantReply']);
    Route::post('/conversations/{conversation_id}/resolve', [ConversationController::class, 'resolve']);

    // Credits / Checkout (Sprint 10 & S15 & S17)
    Route::get('/credits/mp-public-config', [CreditController::class, 'publicConfig']);
    Route::get('/credits/pricing', [CreditController::class, 'pricing']);
    Route::post('/credits/checkout', [CreditController::class, 'checkout']);
    Route::post('/credits/checkout/card', [CreditController::class, 'checkoutCard']);
    Route::get('/credits/orders/{id}', [CreditController::class, 'showOrder']);
    Route::put('/admin/credits/pricing', [AdminController::class, 'updatePricing']);
});

// --- Rotas públicas de entidades ---
Route::get('/entities/{unique_code}', [EntityController::class, 'show']);
Route::post('/entities/{unique_code}/messages', [MessageController::class, 'storePublic'])->middleware('throttle:public-messages');

// Chat mediado — lado do benfeitor (público)
Route::middleware('throttle:public-messages')->group(function () {
    Route::post('/entities/{unique_code}/conversations', [ConversationController::class, 'store']);
    Route::get('/entities/{unique_code}/conversations/{conversation_id}', [ConversationController::class, 'show']);
    Route::post('/entities/{unique_code}/conversations/{conversation_id}/messages', [ConversationController::class, 'addMessage']);
});

Route::post('/entities/{unique_code}/conversations/recover', [ConversationController::class, 'recover'])
    ->middleware('throttle:conversation-recovery');

// Declaração de emergência (pública)
Route::post('/entities/{unique_code}/declare-emergency', [EmergencyController::class, 'declare'])
    ->middleware('throttle:public-messages');
