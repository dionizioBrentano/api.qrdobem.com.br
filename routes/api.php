<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EntityController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\Api\V1\PartnerController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\ConfirmationController;
use App\Http\Controllers\CauseController;
use App\Http\Controllers\DisbursementController;
use App\Http\Controllers\DonationCauseController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SponsorshipController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\QrBatchController;
use App\Http\Controllers\SpaceController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PanicController;
use App\Http\Controllers\TwoFactorController;
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

    // Identidade da pessoa: contas múltiplas e vínculos (Fase 0, F10).
    // Requisitos TX-R02, TX-R03 e TX-R04 do PLANO_TRILHAS_2026-08.md.
    //
    // Nenhuma destas rotas aceita CPF vindo do cliente: todas partem do
    // tenant autenticado pelo JWT. CPF não é segredo e não pode ser
    // credencial de leitura de vínculo. Ver MeController.
    Route::get('/me/accounts', [MeController::class, 'accounts']);
    Route::get('/me/links', [MeController::class, 'links']);
    Route::post('/me/switch-account', [MeController::class, 'switchAccount']);

    // --- Módulo Premium de Saúde (Fase 6, T1-R08 a T1-R11) ---
    Route::get('/entities/{unique_code}/health', [HealthController::class, 'show']);
    Route::post('/entities/{unique_code}/health/diary', [HealthController::class, 'storeDiaryEntry']);
    Route::post('/entities/{unique_code}/prescriptions', [HealthController::class, 'storePrescription']);
    Route::put('/prescriptions/{prescription}', [HealthController::class, 'updatePrescription']);
    // Exporta para a agenda nativa de Android e iOS (T1-R11).
    Route::get('/prescriptions/{prescription}/calendar.ics', [HealthController::class, 'calendar']);

    // Código de barras → produto, com confirmação do usuário (T1-R09).
    Route::post('/medications/lookup', [HealthController::class, 'lookupMedication']);
    Route::post('/medications/{medication}/confirm', [HealthController::class, 'confirmMedication']);

    // --- Apadrinhamento digital (Fase 6, T2-R06) ---
    Route::post('/b/{unique_code}/sponsor', [SponsorshipController::class, 'store']);
    Route::get('/sponsorships/mine', [SponsorshipController::class, 'mine']);
    Route::post('/sponsorships/{sponsorship}/end', [SponsorshipController::class, 'end']);

    // --- Empresas: chaves de API e motor de confirmação (Fase 5) ---
    Route::get('/spaces/{space}/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/spaces/{space}/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{key}', [ApiKeyController::class, 'revoke']);

    Route::get('/spaces/{space}/confirmation-templates', [ConfirmationController::class, 'templates']);
    Route::post('/spaces/{space}/confirmation-templates', [ConfirmationController::class, 'storeTemplate']);
    Route::post('/spaces/{space}/confirmation-actors', [ConfirmationController::class, 'storeActor']);
    Route::post('/spaces/{space}/confirmation-actors/{actor}/password', [ConfirmationController::class, 'setActorPassword']);
    Route::get('/spaces/{space}/confirmations', [ConfirmationController::class, 'index']);

    // --- Doações a causa / checkout (Fase 4, T4-R01 a T4-R04) ---
    // Bounded context DonationCause (tabela donation_causes). A CRIAÇÃO
    // (POST /donation-causes) NÃO fica aqui: doar não exige conta (guest
    // checkout) — está no grupo de auth OPCIONAL, mais abaixo. O que sobra
    // aqui é o que só faz sentido logado: as MINHAS doações e a assinatura
    // recorrente, que pertencem a um tenant.
    Route::get('/donation-causes/mine', [DonationCauseController::class, 'mine']);
    Route::post('/donation-causes/subscribe', [DonationCauseController::class, 'subscribe']);
    Route::post('/donation-causes/{subscription}/cancel-subscription', [DonationCauseController::class, 'cancelSubscription']);

    // --- Beneficiários (Fase 4, T4-R05, T4-R09) ---
    Route::post('/spaces/{space}/beneficiaries', [BeneficiaryController::class, 'store']);
    Route::get('/spaces/{space}/beneficiaries', [BeneficiaryController::class, 'index']);
    Route::put('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update']);
    Route::post('/beneficiaries/{beneficiary}/proof-password', [BeneficiaryController::class, 'setProofPassword']);

    // --- Repasses (Fase 4, T4-R03, T4-R06, T4-R08) ---
    Route::post('/spaces/{space}/disbursements', [DisbursementController::class, 'store']);
    Route::get('/spaces/{space}/disbursements', [DisbursementController::class, 'index']);
    Route::post('/disbursements/{disbursement}/transition', [DisbursementController::class, 'transition']);
    Route::post('/disbursements/{disbursement}/reimbursement', [DisbursementController::class, 'authorizeReimbursement']);

    // Espaços de trilha (F1) e guarda-chuva (Fase 3, T2-R01, T2-R02).
    Route::get('/spaces', [SpaceController::class, 'index']);
    Route::post('/spaces', [SpaceController::class, 'store']);
    Route::get('/spaces/{space}', [SpaceController::class, 'show']);
    Route::put('/spaces/{space}', [SpaceController::class, 'update']);
    Route::post('/spaces/{space}/children', [SpaceController::class, 'attachChild']);

    // Vitrine da causa (Fase 3, T2-R04, T2-R05).
    Route::put('/spaces/{space}/cause', [CauseController::class, 'update']);
    Route::post('/spaces/{space}/cause/publish', [CauseController::class, 'publish']);

    // Mídia com moderação (Fase 3, T2-R05).
    Route::post('/spaces/{space}/media', [MediaController::class, 'store']);
    Route::get('/spaces/{space}/media', [MediaController::class, 'index']);
    Route::post('/media/{media}/moderate', [MediaController::class, 'moderate']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    // QR Codes em lote e folha de impressão (Fase 3, T2-R03).
    Route::post('/spaces/{space}/qr-batches', [QrBatchController::class, 'store']);
    Route::get('/spaces/{space}/qr-batches', [QrBatchController::class, 'index']);
    Route::get('/qr-batches/{batch}', [QrBatchController::class, 'show']);
    Route::get('/qr-batches/{batch}/print', [QrBatchController::class, 'print']);

    // Árvore genealógica do espaço familiar (Fase 1, T1-R02).
    Route::get('/spaces/{space}/family', [FamilyController::class, 'index']);
    Route::post('/spaces/{space}/family', [FamilyController::class, 'store']);
    Route::delete('/spaces/{space}/family/{relationship}', [FamilyController::class, 'destroy']);

    // Botão de Pânico (T1-R07) — versão rústica, alarme no app + e-mail.
    Route::post('/spaces/{space}/panic', [PanicController::class, 'trigger']);
    Route::get('/spaces/{space}/panic', [PanicController::class, 'index']);
    Route::post('/panic/{event}/resolve', [PanicController::class, 'resolve']);

    // 2FA opcional por aplicativo autenticador (Fase 1, T1-R05).
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/2fa/verify', [TwoFactorController::class, 'verify']);
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);

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

// --- Mapa de calor (Fase 6, T2-R07) — DESATIVADO em 06/08/2026 ---
//
// Rotas removidas por decisão do proprietário. O controller segue em
// app/Http/Controllers/HeatmapController.php e a agregação continua sendo
// alimentada a cada leitura de QR (EntityController::recordHeatmap), então
// quando o mapa voltar já haverá histórico.
//
// Para reativar, devolver estas duas linhas:
//   Route::get('/heatmap', [HeatmapController::class, 'index']);
//   Route::get('/heatmap/summary', [HeatmapController::class, 'summary']);

// --- API PÚBLICA DE PARCEIROS — /api/v1 (Fase 5, T3-R01) ---
//
// Autenticação por chave (X-Api-Key + X-Api-Secret), escopo declarado em
// cada rota e rate limit por parceiro. O parceiro só enxerga o próprio
// espaço: nenhum endpoint aceita `space_id` do cliente.
//
// O caminho é versionado de propósito. Quando houver v2, as duas convivem:
// parceiro corporativo não atualiza integração no nosso ritmo.
Route::prefix('v1')->group(function () {
    Route::middleware('api.key:entities.read')->group(function () {
        Route::get('/entities', [PartnerController::class, 'listEntities']);
        Route::get('/entities/{code}', [PartnerController::class, 'showEntity']);
    });

    Route::middleware('api.key:entities.write')->group(function () {
        Route::post('/entities', [PartnerController::class, 'createEntity']);
    });

    Route::middleware('api.key:confirmations.read')->group(function () {
        Route::get('/confirmations', [PartnerController::class, 'listConfirmations']);
    });

    Route::middleware('api.key:confirmations.write')->group(function () {
        Route::post('/confirmations', [PartnerController::class, 'storeConfirmation']);
    });
});

// Confirmação por leitura do QR (Fase 5, T3-R05/R06/R07).
// Pública com throttle: quem confirma é o funcionário no chão de fábrica
// ou o porteiro na guarita, sem conta no sistema. A prova vem da senha do
// confirmador — o segundo fator que o requisito de EPI exige.
Route::post('/entities/{unique_code}/confirm', [ConfirmationController::class, 'confirm'])
    ->middleware('throttle:public-messages');

// --- URL única do beneficiário (Fase 4, T4-R05, T4-R06, T4-R07) ---
//
// Públicas por definição: o beneficiário não tem conta no sistema. A
// credencial é a combinação do código único (que só ele tem) com o fator
// de contraprova (que só ele ou o tutor sabem), mais o throttle.
//
// A confirmação por tutor exige tutor autenticado — o controller verifica,
// e a rota aceita o token opcionalmente por `?id_token=` do FirebaseAuth.
Route::middleware(['throttle:public-messages', 'auth.firebase.optional'])->group(function () {
    Route::get('/b/{unique_code}', [BeneficiaryController::class, 'publicShow']);
    Route::post('/b/{unique_code}/needs', [BeneficiaryController::class, 'storeNeed']);
    Route::post('/b/{unique_code}/disbursements/{disbursement}/confirm', [DisbursementController::class, 'confirm']);
    Route::post('/b/{unique_code}/disbursements/{disbursement}/proof', [DisbursementController::class, 'storeProof']);
});

// --- Doação a causa SEM login (guest checkout) — auth OPCIONAL ---
//
// Doar não exige conta: o doador se identifica por doação (nome, e-mail,
// CPF). Com Bearer válido, o middleware preenche $request->tenant e a doação
// é atribuída à conta; sem token, segue como guest. É o MESMO controller,
// o MESMO cálculo (DonationFeeCalculator) e a MESMA persistência de rateio
// em donation_causes.
//
// Throttle forte por IP: a criação dispara pagamento; o preview é só cálculo,
// sem PII, e por isso tem limite mais folgado.
Route::middleware('auth.firebase.optional')->group(function () {
    Route::post('/donation-causes', [DonationCauseController::class, 'store'])
        ->middleware('throttle:donation-create');

    Route::post('/donation-causes/preview', [DonationCauseController::class, 'preview'])
        ->middleware('throttle:donation-preview');
});

// Últimas doações de uma causa — prova social do lado do dinheiro. Path
// cause-scoped mantido (é leitura pública sob a causa, não checkout).
Route::get('/causes/{slug}/donations', [DonationCauseController::class, 'publicList']);

// --- Vitrine pública das causas (Fase 3, T2-R04) ---
// Sem autenticação: a vitrine existe para ser vista por quem ainda não é
// usuário. Só causa publicada aparece.
Route::get('/causes', [CauseController::class, 'index']);
Route::get('/causes/{slug}', [CauseController::class, 'show']);

// Entrega de mídia. Rota pública, mas só serve arquivo APROVADO — é essa
// verificação que torna seguro guardar aprovada e reprovada no mesmo lugar.
Route::get('/media/{media}', [MediaController::class, 'serve']);

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

// Botão de Pânico por leitura de QR (público).
// Sem autenticação por definição: quem encontrou a pessoa na rua não tem
// conta. Throttle aplicado para conter acionamento em massa.
Route::post('/entities/{unique_code}/panic', [PanicController::class, 'triggerPublic'])
    ->middleware('throttle:public-messages');

// Declaração de emergência (pública)
Route::post('/entities/{unique_code}/declare-emergency', [EmergencyController::class, 'declare'])
    ->middleware('throttle:public-messages');
