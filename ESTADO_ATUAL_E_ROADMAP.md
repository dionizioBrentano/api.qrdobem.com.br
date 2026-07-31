# QR do Bem — API: Estado Atual e Roadmap

**Atualizado em:** 01/07/2026  
**Proprietário:** Dionizio (Tchutcho) — dioniiziobrentano@gmail.com  
**Ambiente:** CPanel shared hosting (hospedameusite.com.br), IP 192.99.36.226  
**Stack:** Laravel 13, Firebase Authentication (JWT RS256), SMTP direto (mail.qrdobem.com.br)  
**Build/Deploy:** Sem ambiente local. Build do frontend no Windows, upload manual via CPanel. Backend diretamente no servidor.  
**REGRA:** Alterações uma por vez, testar, confirmar, próxima. NUNCA fazer múltiplas alterações simultaneamente.  
**REGRA:** NUNCA usar Tenant::first(). Sempre especificar IDs exatos.  
**REGRA:** Especificar EXATAMENTE onde rodar cada comando (SSH, browser, local).  

---

## 1. O QUE ESTÁ COMPLETO E FUNCIONANDO

### 1.1 Segurança (auditoria de 10 itens — 30/06/2026)
- [x] JWT RS256 com verificação de assinatura real (chaves públicas Google)
- [x] IDOR corrigido no markAsRead (MessageController)
- [x] Rate limiting (60/min API, 5/min OTP, 10/min public messages)
- [x] Criptografia AES-256-CBC nas EntityMessages (sender_name, sender_contact, message)
- [x] Sanitização de custom_attributes (max 20 attrs, strip_tags)
- [x] Dados sensíveis removidos do endpoint público show()
- [x] AdminController corrigido para usar $request->tenant
- [x] SoftDeletes em Tenant, Organization, Entity, CreditBatch

### 1.2 Arquitetura Organizacional (US1.1)
- [x] Transação de registro: FirebaseAuth cria Tenant + Organization Matriz + vínculo organization_user
- [x] Entities pertencem a Organization (não a Tenant) — migração `2026_06_28_000001`
- [x] CreditBatches pertencem a Organization (sem fallback pessoal)
- [x] EntityController consulta por organization_id

### 1.3 Email
- [x] SMTP direto configurado (mail.qrdobem.com.br:465, SSL)
- [x] OTP funciona: envia código de 6 dígitos por email via contato@qrdobem.com.br

### 1.4 Frontend React deployado
- [x] Build Vite em ~/qrdobem.com.br/
- [x] Firebase REST API (sem SDK pesado)
- [x] Login com email/senha funciona
- [x] Dashboard, mensagens, admin, página pública

### 1.5 Limpezas realizadas (01/07/2026)
- [x] `routes/EntityController.php` — apagado localmente (verificar se foi apagado no servidor também)
- [x] `Tenant.php` — relação `entities()` removida localmente (verificar se foi subido para o servidor)
- [x] Banco de dados limpo com `migrate:fresh` no servidor — tabelas recriadas do zero

---

## 2. O QUE ESTÁ QUEBRADO / PRECISA MUDAR

### 2.1 email_verified do Firebase — JÁ REMOVIDO LOCALMENTE

**Arquivo:** `app/Http/Middleware/FirebaseAuth.php`  
**Estado LOCAL:** Bloco de auto-ativação por `email_verified` já foi REMOVIDO. Linhas 74-75 agora são apenas um comentário explicando que profile_status é alterado apenas pelo fluxo de onboarding.  
**Estado SERVIDOR:** Verificar se o arquivo local já foi subido. Se não, subir `FirebaseAuth.php`.

**Decisão:** O `email_verified` do Firebase é IGNORADO. Validação de email é pelo OTP do sistema. O `profile_status` só muda para `active` quando: email verificado por OTP + CPF + telefone.

### 2.2 OtpController.verifyOtp() muda profile_status incorretamente

**Arquivo:** `app/Http/Controllers/OtpController.php`, linhas 74-77  
**Problema:** Quando o OTP é verificado, o código muda `profile_status` para `active`:

```php
$tenant = Tenant::where('firebase_uid', $uid)->first();
if ($tenant && $tenant->profile_status !== 'active') {
    $tenant->update(['profile_status' => 'active']);
}
```

**Decisão tomada:** O OTP deve setar `email_verified_at` (timestamp) no tenant. A mudança para `active` só acontece quando TODOS os requisitos estiverem preenchidos (email OTP + CPF + telefone). Ver seção 3.3.

### 2.3 Campos faltantes no Tenant

**Arquivo:** `database/migrations/2026_06_27_000002_add_onboarding_fields_to_tenants.php`  
**Estado atual dos campos em tenants:**
- name, document_number (unique, auto: 'B2C-uniqid'), firebase_uid, is_active (migration base)
- role (enum), qr_quota (migration 000004)
- cpf (nullable), dob (nullable), phone (nullable), profile_status (enum) (migration 000002)
- softDeletes (migration us11)

**Campos que FALTAM (criar nova migration):**
- `email` (string, nullable) — salvar email do Firebase para uso interno
- `email_verified_at` (timestamp, nullable) — data/hora que verificou por OTP
- `address_street` (string, nullable)
- `address_number` (string, nullable)
- `address_complement` (string, nullable)
- `address_neighborhood` (string, nullable)
- `address_city` (string, nullable)
- `address_state` (string, nullable, 2 chars)
- `address_zipcode` (string, nullable, 8 chars)

### 2.4 Tabelas que não existem e precisam ser criadas

**`tenant_documents`** — guarda de documentos de identificação:
```
id, tenant_id (FK), document_type (string), document_number (encrypted), 
document_country (string, default 'BR'), is_primary (boolean), 
verified_at (timestamp, nullable), timestamps
```

**`tenant_term_acceptances`** — registros de aceite de termos:
```
id, tenant_id (FK), entity_id (FK nullable), term_type (string: person/pet/object),
term_version (string), ip_address (string), user_agent (string), 
accepted_at (timestamp), timestamps
```

### 2.5 Campo status faltante na tabela entities

A tabela `entities` tem `is_active` (boolean), mas precisa de um campo `status` (enum) mais granular:
- `pending_term` — entidade criada, crédito debitado, termo não aceito ainda
- `active` — termo aceito, QR code operacional
- `suspended` — desativado

O endpoint público `show()` só deve retornar dados se `status = 'active'`.

### 2.6 APP_NAME no .env do servidor

**MAIL_FROM_ADDRESS já está correto:** `contato@qrdobem.com.br`  
**MAIL_FROM_NAME usa `${APP_NAME}`** — corrigir APP_NAME resolve os dois.

**No servidor (SSH), editar ~/api.qrdobem.com.br/.env:**
```
APP_NAME="QR do Bem"                          ← atualmente Laravel
```
Depois rodar: `php artisan config:cache`

**Config de email atual no servidor (SMTP direto):**
```
MAIL_HOST=mail.qrdobem.com.br
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=contato@qrdobem.com.br
```
Nota: Brevo relay foi discutido como alternativa para melhorar entrega no Hotmail, mas NÃO está configurado no servidor.

### 2.7 Superadmin não configurado

O tenant do Dionizio precisa ter `role = 'superadmin'`. Após registrar novamente (banco foi limpo), rodar no SSH via tinker:
```php
php artisan tinker
// Usar o ID correto do tenant do Dionizio (verificar qual é após novo registro)
Tenant::where('firebase_uid', 'UID_DO_DIONIZIO')->update(['role' => 'superadmin']);
```

### 2.8 addQuota no AdminController

A rota `POST /admin/tenants/{id}/add-quota` existe mas o método `addQuota` não está implementado. Está marcado como "Deprecated, usa Lote" na rota. Pode ser removido ou implementado como alias para createBatch.

---

## 3. O QUE PRECISA SER IMPLEMENTADO (ROADMAP)

### 3.1 Nova Migration: campos do tenant + tabelas novas

Criar `database/migrations/2026_07_01_000001_add_profile_and_documents.php`:

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Campos novos no tenant
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->timestamp('email_verified_at')->nullable()->after('phone');
            $table->string('address_street')->nullable();
            $table->string('address_number')->nullable();
            $table->string('address_complement')->nullable();
            $table->string('address_neighborhood')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_zipcode', 9)->nullable();
        });

        // 2. Documentos do tenant
        Schema::create('tenant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // cpf, cin, rg, cnh, passport, etc.
            $table->text('document_number'); // encrypted via cast
            $table->string('document_country', 5)->default('BR');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type']);
        });

        // 3. Aceite de termos
        Schema::create('tenant_term_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('term_type'); // person, pet, object
            $table->string('term_version');
            $table->string('ip_address');
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });

        // 4. Status na entity
        Schema::table('entities', function (Blueprint $table) {
            $table->enum('status', ['pending_term', 'active', 'suspended'])
                  ->default('pending_term')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('status');
        });
        Schema::dropIfExists('tenant_term_acceptances');
        Schema::dropIfExists('tenant_documents');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'email', 'email_verified_at',
                'address_street', 'address_number', 'address_complement',
                'address_neighborhood', 'address_city', 'address_state', 'address_zipcode'
            ]);
        });
    }
};
```

### 3.2 Models Novos

**`app/Models/TenantDocument.php`:**
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantDocument extends Model
{
    protected $fillable = [
        'tenant_id', 'document_type', 'document_number',
        'document_country', 'is_primary', 'verified_at',
    ];

    protected $casts = [
        'document_number' => 'encrypted',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

**`app/Models/TenantTermAcceptance.php`:**
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantTermAcceptance extends Model
{
    protected $fillable = [
        'tenant_id', 'entity_id', 'term_type',
        'term_version', 'ip_address', 'user_agent', 'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }
}
```

### 3.3 ProfileController (NOVO)

**`app/Http/Controllers/ProfileController.php`** — coleta progressiva de dados:

```php
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TenantDocument;

class ProfileController extends Controller
{
    // Retorna dados atuais do perfil + o que falta
    public function show(Request $request)
    {
        $tenant = $request->tenant;
        $missing = $this->getMissingFields($tenant);

        return response()->json([
            'tenant' => $tenant,
            'documents' => $tenant->documents,
            'missing_for_purchase' => $missing['purchase'],
            'missing_for_entity' => $missing['entity'],
            'can_purchase' => empty($missing['purchase']),
            'can_create_entity' => empty($missing['entity']),
        ]);
    }

    // Atualiza dados do perfil (nome, telefone, endereço)
    public function update(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address_street' => 'sometimes|string|max:255',
            'address_number' => 'sometimes|string|max:20',
            'address_complement' => 'sometimes|string|max:255',
            'address_neighborhood' => 'sometimes|string|max:255',
            'address_city' => 'sometimes|string|max:255',
            'address_state' => 'sometimes|string|size:2',
            'address_zipcode' => 'sometimes|string|max:9',
        ]);

        $tenant->update($validated);

        // Verifica se agora pode ser ativado
        $this->checkAndActivate($tenant->fresh());

        return response()->json([
            'message' => 'Perfil atualizado.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    // Adiciona documento de identificação
    public function addDocument(Request $request)
    {
        $tenant = $request->tenant;

        $validated = $request->validate([
            'document_type' => 'required|string|max:50',
            'document_number' => 'required|string|max:50',
            'document_country' => 'sometimes|string|max:5',
            'is_primary' => 'sometimes|boolean',
        ]);

        // Validação específica de CPF (dígito verificador)
        if ($validated['document_type'] === 'cpf') {
            if (!$this->validateCpf($validated['document_number'])) {
                return response()->json(['error' => 'CPF inválido.'], 422);
            }
            // Atualiza também o campo cpf na tabela tenants (atalho)
            $tenant->update(['cpf' => $validated['document_number']]);
        }

        // Verifica duplicata
        $existing = TenantDocument::where('tenant_id', $tenant->id)
            ->where('document_type', $validated['document_type'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            $doc = $existing;
        } else {
            $doc = TenantDocument::create(array_merge($validated, [
                'tenant_id' => $tenant->id,
            ]));
        }

        // Verifica se agora pode ser ativado
        $this->checkAndActivate($tenant->fresh());

        return response()->json([
            'message' => 'Documento cadastrado.',
            'document' => $doc,
            'tenant' => $tenant->fresh(),
        ]);
    }

    // Verifica se o tenant cumpre todos os requisitos para 'active'
    private function checkAndActivate($tenant)
    {
        if ($tenant->profile_status === 'active') return;

        $hasCpf = !empty($tenant->cpf) || 
                  TenantDocument::where('tenant_id', $tenant->id)
                      ->where('document_type', 'cpf')->exists();
        $hasPhone = !empty($tenant->phone);
        $hasEmailVerified = !empty($tenant->email_verified_at);

        if ($hasCpf && $hasPhone && $hasEmailVerified) {
            $tenant->update(['profile_status' => 'active']);
        }
    }

    // Retorna campos faltantes por operação
    private function getMissingFields($tenant)
    {
        $purchase = [];
        $entity = [];

        if (empty($tenant->email_verified_at)) $purchase[] = 'email_verified';
        if (empty($tenant->cpf) && !TenantDocument::where('tenant_id', $tenant->id)->where('document_type', 'cpf')->exists()) {
            $purchase[] = 'cpf';
        }
        if (empty($tenant->phone)) $purchase[] = 'phone';

        // Para criar entidade, precisa de tudo acima + endereço
        $entity = $purchase;
        if (empty($tenant->address_street)) $entity[] = 'address';

        return ['purchase' => $purchase, 'entity' => $entity];
    }

    // Validação de CPF com dígito verificador
    private function validateCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) !== 11) return false;
        if (preg_match('/^(\d)\1{10}$/', $cpf)) return false; // 000.000.000-00, etc.

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$t] != $d) return false;
        }
        return true;
    }
}
```

### 3.4 Rotas Novas

Adicionar em `routes/api.php` dentro do grupo `auth.firebase`:

```php
// Profile (coleta progressiva)
Route::get('/profile', [ProfileController::class, 'show']);
Route::put('/profile', [ProfileController::class, 'update']);
Route::post('/profile/documents', [ProfileController::class, 'addDocument']);
```

Adicionar o use no topo:
```php
use App\Http\Controllers\ProfileController;
```

### 3.5 Atualizar OtpController.verifyOtp()

Mudar de:
```php
if ($tenant && $tenant->profile_status !== 'active') {
    $tenant->update(['profile_status' => 'active']);
}
```

Para:
```php
if ($tenant) {
    $tenant->update(['email_verified_at' => now()]);
    
    // Verifica se agora cumpre todos os requisitos para ativação
    $hasCpf = !empty($tenant->cpf);
    $hasPhone = !empty($tenant->phone);
    if ($hasCpf && $hasPhone) {
        $tenant->update(['profile_status' => 'active']);
    }
}
```

### 3.6 Atualizar FirebaseAuth.php

Bloco email_verified já removido localmente. Falta apenas adicionar `'email' => $email` no auto-cadastro:
```php
// No auto-cadastro (dentro do DB::transaction), adicionar o campo email:
$tenant = Tenant::create([
    'name' => $name,
    'email' => $email, // ← ADICIONAR (campo novo, requer migration 3.1 primeiro)
    'document_number' => 'B2C-' . uniqid(),
    'firebase_uid' => $uid,
    'role' => 'ngo',
    'qr_quota' => 0,
    'profile_status' => 'incomplete',
    'is_active' => true,
]);
```

### 3.7 Atualizar Tenant Model

Adicionar ao `$fillable`:
```php
'email', 'email_verified_at',
'address_street', 'address_number', 'address_complement',
'address_neighborhood', 'address_city', 'address_state', 'address_zipcode',
```

Adicionar relações:
```php
public function documents()
{
    return $this->hasMany(TenantDocument::class);
}

public function termAcceptances()
{
    return $this->hasMany(TenantTermAcceptance::class);
}
```

### 3.8 Gates no EntityController.store()

O `store()` atual (em `app/Http/Controllers/EntityController.php`) já valida:
- Organização existe
- Owner da organização tem profile_status === 'active'
- Lote de crédito disponível na organização

**Adicionar:**
- Verificação de endereço do tenant
- Exigência de aceite de termo por tipo (receber `term_accepted: true` e `term_type` no request)
- Registrar aceite na tabela `tenant_term_acceptances`
- Setar `entity.status = 'active'` (ou `pending_term` se o termo não foi aceito na mesma request)

### 3.9 Integração Mercado Pago (futuro)

Dados que já teremos quando o usuário estiver `active`:
- **PIX:** email, nome, CPF ✓
- **Cartão:** email, nome, CPF, endereço ✓ (se já preencheu para QR code)

O Mercado Pago cuida do PCI compliance (dados do cartão). Nunca armazenar número de cartão.

---

## 4. CONFIGURAÇÃO DE EMAIL (REFERÊNCIA)

### Config Atual no Servidor (SMTP Direto)
```env
MAIL_MAILER=smtp
MAIL_HOST=mail.qrdobem.com.br
MAIL_PORT=465
MAIL_USERNAME=contato@qrdobem.com.br
MAIL_PASSWORD="<definido apenas no .env do servidor>"
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="contato@qrdobem.com.br"
MAIL_FROM_NAME="${APP_NAME}"
```

### Config Alternativa (Brevo SMTP Relay — NÃO configurada no servidor)
Brevo relay foi discutido como solução para entrega no Hotmail (IP compartilhado 192.99.36.226 tem PTR apontando para host4527.hospedameusite.net, Hotmail pode rejeitar). Se necessário no futuro:
```env
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

---

## 5. FIREBASE

- **Projeto:** qr-do-bem
- **Project ID:** qr-do-bem
- **API Key:** AIzaSyBpvh_RBN1oujAiPqUmXpHWEKwxgkICJSo
- **App ID:** 1:431626927511:web:067b0cdf27bdd0e6c358d2
- **Auth Domain:** qr-do-bem.firebaseapp.com
- **Frontend usa REST API** (identitytoolkit.googleapis.com), NÃO o Firebase JS SDK

---

## 6. ESTRUTURA DE ARQUIVOS

```
API/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AdminController.php      ← superadmin: getTenants, createBatch, toggleBatch
│   │   │   ├── EntityController.php      ← CRUD de entidades (QR codes)
│   │   │   ├── MessageController.php     ← inbox + mensagens públicas
│   │   │   ├── OtpController.php         ← enviar/verificar OTP + endpoint /auth/me
│   │   │   └── ProfileController.php     ← CRIAR: coleta progressiva de dados
│   │   ├── Middleware/
│   │   │   └── FirebaseAuth.php          ← JÁ MODIFICADO localmente (email_verified removido), falta adicionar campo email
│   │   └── Requests/
│   │       └── EntityStoreRequest.php
│   ├── Models/
│   │   ├── Tenant.php                    ← MODIFICAR: adicionar campos e relações
│   │   ├── Organization.php
│   │   ├── Entity.php
│   │   ├── CreditBatch.php
│   │   ├── EntityMessage.php
│   │   ├── AuditLog.php
│   │   ├── CustomAttribute.php
│   │   ├── OtpCode.php
│   │   ├── PriceTable.php
│   │   ├── TenantDocument.php            ← CRIAR
│   │   └── TenantTermAcceptance.php      ← CRIAR
│   └── Mail/
│       └── VerificationCodeMail.php
├── database/migrations/
│   ├── ... (existentes)
│   └── 2026_07_01_000001_add_profile_and_documents.php  ← CRIAR
├── routes/
│   ├── api.php                           ← MODIFICAR: adicionar rotas de profile
│   └── (EntityController.php já apagado localmente — verificar servidor)
└── config/
    ├── cors.php                          ← OK (qrdobem.com.br nos allowed_origins)
    └── services.php                      ← firebase.project_id = qr-do-bem
```

---

## 7. FLUXO COMPLETO DO SISTEMA (VISÃO GERAL)

```
1. Login (Firebase) → token JWT
2. FirebaseAuth middleware → verifica token → cria/busca tenant (profile_status='incomplete')
3. Dashboard → mostra aviso "perfil incompleto"
4. Usuário navega livremente (mensagens, dashboard, etc.)
5. Tenta comprar crédito → Gate 1: precisa de email OTP + CPF + telefone
   → Se faltar algo, frontend mostra formulário pedindo os dados faltantes
   → Backend valida e grava → quando tudo OK, profile_status = 'active'
6. Tenta criar entidade (QR Code) → Gate 2: precisa de endereço + aceite do termo
   → Se faltar endereço, frontend pede
   → Aceite do termo registrado com IP + timestamp + versão
   → Crédito debitado da organização
   → QR Code gerado com status 'active'
7. Público lê QR Code → endpoint /entities/{unique_code} → retorna dados (se status=active)
8. Público envia mensagem → endpoint /entities/{unique_code}/messages
9. Proprietário lê mensagens → /messages (filtrado por organizações do tenant)
```
