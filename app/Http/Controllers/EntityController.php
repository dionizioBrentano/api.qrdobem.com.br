<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\AuditLog;
use App\Http\Requests\EntityStoreRequest;
use App\Models\CreditBatch;
use App\Models\Organization;
use App\Models\TenantTermAcceptance;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EntityController extends Controller
{
    public function __construct(private QrCodeService $qrCode)
    {
    }

    public function index(Request $request)
    {
        $tenant = $request->tenant;
        
        // Recebe do Front-end qual o contexto organizacional ativo
        // Se não mandar, pega a primeira organização que o usuário é membro
        $orgId = $request->input('organization_id') 
            ?? $tenant->organizations()->first()->id ?? null;

        if (!$orgId) {
             return response()->json(['error' => 'Nenhuma organização vinculada.'], 403);
        }

        // Soma lotes da Organização
        $activeQuota = CreditBatch::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where(function($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->sum('amount_available');

        // Pega as entidades da Organização
        $entities = Entity::where('organization_id', $orgId)->orderBy('id', 'desc')->get();
        
        $userOrgs = $tenant->organizations()->get()->map(function($o) {
            return ['id' => $o->id, 'name' => $o->name];
        });

        return response()->json([
            'profile_status' => $tenant->profile_status,
            'quota' => $activeQuota,
            'organizations' => $userOrgs,
            'active_org_id' => $orgId,
            'entities' => $entities->map(function($e) {
                return [
                    'unique_code' => $e->unique_code,
                    'type' => $e->type,
                    'name' => $e->encrypted_name,
                    'url' => $this->qrCode->urlFor($e->unique_code),
                    // Endpoint do QR: o frontend não precisa saber gerar imagem.
                    'qr_code_url' => url("/api/entities/{$e->unique_code}/qrcode"),
                    'created_at' => $e->created_at ? $e->created_at->format('d/m/Y') : 'Hoje',
                    'is_active' => $e->is_active,
                    'status' => $e->status,
                ];
            })
        ]);
    }

    public function store(EntityStoreRequest $request)
    {
        $tenant = $request->tenant; 

        $orgId = $request->input('organization_id') 
            ?? $tenant->organizations()->first()->id ?? null;

        if (!$orgId) {
             return response()->json(['error' => 'Nenhuma organização vinculada.'], 403);
        }

        // Validação US1.1: O bloqueio de acesso (profile_status) 
        // verifica a integridade dos dados fiscais do responsável financeiro da Matriz.
        $organization = Organization::find($orgId);
        $owner = $organization->owner;
        
        if (!$owner || $owner->profile_status !== 'active') {
            return response()->json([
                'error' => 'O cadastro do responsável financeiro do grupo está pendente.',
                'code' => 'PROFILE_INCOMPLETE'
            ], 403);
        }

        // Gate 2: Endereço completo obrigatório para criar entidade (usar QR)
        if (empty($tenant->address_street) || empty($tenant->address_city) || empty($tenant->address_state) || empty($tenant->address_zipcode)) {
            return response()->json([
                'error' => 'Endereço completo é obrigatório para registrar entidades.',
                'code' => 'ADDRESS_REQUIRED',
                'missing' => 'address',
            ], 403);
        }

        // Gate 2: Termo de responsabilidade obrigatório por tipo de entidade
        $entityType = $request->type;
        $termType = 'responsibility_' . $entityType; // responsibility_person, responsibility_pet, responsibility_object
        $termVersion = '1.0';

        if (!$request->input('accept_term')) {
            return response()->json([
                'error' => 'É necessário aceitar o termo de responsabilidade.',
                'code' => 'TERM_REQUIRED',
                'term_type' => $termType,
                'term_version' => $termVersion,
            ], 403);
        }

        // Inteligência de Consumo: Busca lote ativo da organização (sem fallback pessoal)
        $validBatch = CreditBatch::where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('amount_available', '>', 0)
            ->where(function($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('expires_at', 'asc') // Consome o que expira primeiro
            ->first();

        if (!$validBatch) {
            return response()->json(['error' => 'Saldo insuficiente. A organização precisa adquirir créditos.'], 402);
        }

        $uniqueCode = (string) Str::uuid();

        $entity = Entity::create([
            'organization_id' => $orgId,
            'credit_batch_id' => $validBatch->id,
            'unique_code' => $uniqueCode,
            'type' => $request->type,
            'encrypted_name' => $request->name,
            'encrypted_contact_phone' => $request->contact_phone,
            'encrypted_contact_email' => $request->contact_email,
            'encrypted_medical_info' => $request->medical_info,
            'encrypted_additional_info' => $request->additional_info,
            'is_active' => true,
            'status' => 'active', // Já aceitou o termo acima
        ]);

        // Rastreabilidade: registra aceite do termo de responsabilidade
        TenantTermAcceptance::create([
            'tenant_id' => $tenant->id,
            'entity_id' => $entity->id,
            'term_type' => $termType,
            'term_version' => $termVersion,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accepted_at' => now(),
        ]);

        if ($request->has('custom_attributes') && is_array($request->custom_attributes)) {
            $customAttrs = $request->custom_attributes;

            // Fix 9: Sanitização — máximo 20 atributos, chave max 100 chars, valor max 500 chars
            if (count($customAttrs) > 20) {
                return response()->json(['error' => 'Máximo de 20 atributos personalizados.'], 422);
            }

            foreach ($customAttrs as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    continue;
                }
                $key = substr(strip_tags($key), 0, 100);
                $value = substr(strip_tags($value), 0, 500);

                $entity->customAttributes()->create([
                    'key' => $key,
                    'value' => $value,
                ]);
            }
        }

        // Desconta a cota da organização
        $validBatch->decrement('amount_available');
        if ($validBatch->amount_available <= 0) {
            $validBatch->update(['status' => 'exhausted']);
        }

        return response()->json([
            'message' => 'Entidade registrada com sucesso.',
            'unique_code' => $uniqueCode,
            'url' => $this->qrCode->urlFor($uniqueCode),
            // SVG pronto para <img src="...">. Null só se a lib não estiver instalada.
            'qr_code_base64' => $this->qrCode->dataUriFor($uniqueCode),
            'qr_code_url' => url("/api/entities/{$uniqueCode}/qrcode"),
        ], 201);
    }

    /**
     * QR Code de uma entidade, para os frontends whitelabel.
     *
     * Rota autenticada: só quem pertence à organização dona da entidade
     * consegue baixar. O código público em si já é acessível por quem tem o
     * link, mas não faz sentido expor um gerador aberto.
     *
     * Formatos: ?format=svg (imagem crua, ideal para <img> e impressão)
     *           ?format=json (padrão — data URI + URL, ideal para SPA)
     */
    public function qrCode(Request $request, $unique_code)
    {
        $tenant = $request->tenant;

        $entity = Entity::where('unique_code', $unique_code)->first();

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $orgIds = $tenant->organizations()->pluck('organizations.id')->all();

        if (!in_array($entity->organization_id, $orgIds)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $size = (int) $request->input('size', config('qrdobem.size', 512));
        $size = max(128, min($size, 2048));

        $svg = $this->qrCode->svgFor($unique_code, $size);

        if ($svg === null) {
            return response()->json([
                'error' => 'Gerador de QR Code indisponível no servidor.',
                'code' => 'QRCODE_UNAVAILABLE',
            ], 503);
        }

        if ($request->input('format') === 'svg') {
            return response($svg, 200, [
                'Content-Type' => 'image/svg+xml',
                'Content-Disposition' => 'inline; filename="qrdobem-' . $unique_code . '.svg"',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        return response()->json([
            'unique_code' => $unique_code,
            'url' => $this->qrCode->urlFor($unique_code),
            'qr_code_base64' => 'data:image/svg+xml;base64,' . base64_encode($svg),
        ]);
    }

    public function show(Request $request, $unique_code)
    {
        // Só entidade ativa aparece publicamente. 'pending_term' e 'suspended'
        // ficam invisíveis — o público não deve nem saber que o código existe.
        $entity = Entity::with(['organization', 'customAttributes'])
            ->where('unique_code', $unique_code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado ou inativo.'], 404);
        }

        AuditLog::create([
            'entity_id' => $entity->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accessed_at' => now(),
            'location_data' => $request->input('location'), 
        ]);

        // Fix 10: Endpoint público — NÃO expor contact_phone, contact_email, medical_info
        return response()->json([
            'type' => $entity->type,
            'name' => $entity->encrypted_name,
            'additional_info' => $entity->encrypted_additional_info,
            'custom_attributes' => $entity->customAttributes->pluck('value', 'key'),
            'organization' => $entity->organization ? $entity->organization->name : 'Organização Desconhecida',
        ]);
    }
}
