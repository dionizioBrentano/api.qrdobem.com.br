<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityEmergencyDeclaration;
use App\Models\EntityHealthField;
use App\Models\EntityObjectField;
use App\Models\AuditLog;
use App\Http\Requests\EntityStoreRequest;
use App\Models\CreditBatch;
use App\Models\Organization;
use App\Models\TenantTermAcceptance;
use App\Services\PiiDetector;
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
        $entities = Entity::with(['petFields', 'vaccinations', 'objectFields'])
            ->where('organization_id', $orgId)
            ->orderBy('id', 'desc')
            ->get();
        
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
                    'has_active_emergency' => $this->hasActiveEmergency($e),
                    // O tutor sempre vê tudo dos próprios registros, sem filtro
                    // de visibilidade.
                    'pet_info' => $e->type === 'pet' ? $this->ownerPetInfo($e) : null,
                    'object_info' => $e->type === 'object' ? $e->objectFields : null,
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

        // Detecção de PII no texto público impresso do objeto. Diferente do chat,
        // aqui a rejeição é definitiva: não há "enviar mesmo assim" para um texto
        // que vai para etiqueta física.
        $publicLabel = $request->input('object_fields.public_label');

        if ($entityType === 'object' && app(PiiDetector::class)->containsContact($publicLabel)) {
            return response()->json([
                'error' => 'O texto público não pode conter telefone ou e-mail. Por segurança, o contato acontece apenas pelo QR Code.',
                'code' => 'CONTACT_DETECTED',
            ], 422);
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

        // Campos de saúde estruturados (lista fechada, validada em EntityStoreRequest)
        foreach ((array) $request->input('health_fields', []) as $field) {
            $key = $field['field_key'] ?? null;
            $value = $field['field_value'] ?? null;

            if (!$key || $value === null || trim($value) === '') {
                continue;
            }

            $entity->healthFields()->create([
                'field_key' => $key,
                // Blindagem de persistência: os sempre-restritos nascem privados
                // mesmo que algo escape da validação.
                'is_public' => in_array($key, EntityHealthField::ALWAYS_RESTRICTED, true)
                    ? false
                    : filter_var($field['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'field_value' => $value,
            ]);
        }

        // Campos da trilha Pet. Ignorados se a entidade não for do tipo 'pet'.
        if ($entityType === 'pet' && $request->filled('pet_fields')) {
            $petFields = $request->input('pet_fields');

            $entity->petFields()->create([
                'species' => $petFields['species'],
                'species_other_description' => $petFields['species'] === 'other'
                    ? ($petFields['species_other_description'] ?? null)
                    : null,
                'size' => $petFields['size'] ?? null,
                'color' => $petFields['color'] ?? null,
                'is_neutered' => $petFields['is_neutered'] ?? null,
                'physical_description' => $petFields['physical_description'] ?? null,
                'clinical_notes' => $petFields['clinical_notes'] ?? null,
                'reference_contact' => $petFields['reference_contact'] ?? null,
                'size_is_public' => filter_var($petFields['size_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'color_is_public' => filter_var($petFields['color_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_neutered_is_public' => filter_var($petFields['is_neutered_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'physical_description_is_public' => filter_var($petFields['physical_description_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'clinical_notes_is_public' => filter_var($petFields['clinical_notes_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'reference_contact_is_public' => filter_var($petFields['reference_contact_is_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'vaccinations_is_public' => filter_var($petFields['vaccinations_is_public'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);

            foreach ((array) $request->input('vaccinations', []) as $vaccination) {
                $entity->vaccinations()->create([
                    'vaccine_name' => $vaccination['vaccine_name'],
                    'applied_at' => $vaccination['applied_at'],
                ]);
            }
        }

        // Campos da trilha Objeto. Ignorados se a entidade não for do tipo 'object'.
        if ($entityType === 'object' && $request->filled('object_fields')) {
            $objectFields = $request->input('object_fields');

            $entity->objectFields()->create([
                'description' => $objectFields['description'] ?? null,
                'description_is_public' => filter_var($objectFields['description_is_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'public_label' => $objectFields['public_label'] ?? null,
                'handling_fragile' => filter_var($objectFields['handling_fragile'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'handling_light_sensitive' => filter_var($objectFields['handling_light_sensitive'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'handling_keep_refrigerated' => filter_var($objectFields['handling_keep_refrigerated'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'handling_do_not_invert' => filter_var($objectFields['handling_do_not_invert'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'handling_sentimental_value' => filter_var($objectFields['handling_sentimental_value'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'handling_notes_extra' => $objectFields['handling_notes_extra'] ?? null,
            ]);
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

    /**
     * Adiciona uma dose ao histórico de vacinação de um pet.
     *
     * Único ponto de edição pós-criação que existe hoje — não é uma edição
     * geral de entidade.
     */
    public function addVaccination(Request $request, $unique_code)
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

        if ($entity->type !== 'pet') {
            return response()->json([
                'error' => 'Histórico de vacinação só existe para registros do tipo Pet.',
            ], 422);
        }

        $validated = $request->validate([
            'vaccine_name' => 'required|string|max:255',
            'applied_at' => 'required|date_format:Y-m-d',
        ]);

        $vaccination = $entity->vaccinations()->create($validated);

        return response()->json([
            'message' => 'Vacina registrada.',
            'vaccination' => [
                'id' => $vaccination->id,
                'vaccine_name' => $vaccination->vaccine_name,
                'applied_at' => $vaccination->applied_at->format('Y-m-d'),
            ],
        ], 201);
    }

    public function show(Request $request, $unique_code)
    {
        // Só entidade ativa aparece publicamente. 'pending_term' e 'suspended'
        // ficam invisíveis — o público não deve nem saber que o código existe.
        $entity = Entity::with(['organization', 'customAttributes', 'healthFields', 'petFields', 'vaccinations', 'objectFields'])
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
            'health_info' => $this->publicHealthInfo($entity),
            'pet_info' => $entity->type === 'pet' ? $this->publicPetInfo($entity) : null,
            'object_info' => $entity->type === 'object' ? $this->publicObjectInfo($entity) : null,
            'organization' => $entity->organization ? $entity->organization->name : 'Organização Desconhecida',
        ]);
    }

    /**
     * Dados do pet para o dono, sem filtro de visibilidade, com o histórico
     * de vacinas junto.
     */
    private function ownerPetInfo(Entity $entity): ?array
    {
        $pet = $entity->petFields;

        if (!$pet) {
            return null;
        }

        return array_merge($pet->toArray(), [
            'vaccinations' => $entity->vaccinations
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'vaccine_name' => $v->vaccine_name,
                    'applied_at' => $v->applied_at?->format('Y-m-d'),
                ])
                ->values(),
        ]);
    }

    /**
     * Dados do pet visíveis publicamente. `species` é sempre público; os demais
     * respeitam o toggle do tutor.
     */
    private function publicPetInfo(Entity $entity): ?array
    {
        $pet = $entity->petFields;

        if (!$pet) {
            return null;
        }

        $info = [
            'species' => $pet->species,
            'species_other_description' => $pet->species_other_description,
        ];

        foreach (['size', 'color', 'is_neutered', 'physical_description', 'clinical_notes', 'reference_contact'] as $field) {
            if ($pet->{$field . '_is_public'} && $pet->{$field} !== null) {
                $info[$field] = $pet->{$field};
            }
        }

        if ($pet->vaccinations_is_public) {
            $info['vaccinations'] = $entity->vaccinations
                ->map(fn ($v) => [
                    'vaccine_name' => $v->vaccine_name,
                    'applied_at' => $v->applied_at?->format('Y-m-d'),
                ])
                ->values();
        }

        return $info;
    }

    /**
     * Dados do objeto visíveis publicamente. `public_label` e os avisos de
     * manuseio são sempre públicos; a descrição depende do toggle do tutor.
     */
    private function publicObjectInfo(Entity $entity): ?array
    {
        $object = $entity->objectFields;

        if (!$object) {
            return null;
        }

        $info = ['public_label' => $object->public_label];

        if ($object->description_is_public && $object->description !== null) {
            $info['description'] = $object->description;
        }

        foreach (EntityObjectField::HANDLING_FLAGS as $flag) {
            $info[$flag] = $object->{$flag};
        }

        $info['handling_notes_extra'] = $object->handling_notes_extra;

        return $info;
    }

    /**
     * Campos de saúde visíveis na leitura pública normal.
     *
     * Só os marcados como públicos pelo tutor, e nunca `caregiver_contact` —
     * contato direto não circula fora do chat mediado. Os sempre-restritos
     * (`continuous_medications`, `substance_use_risk`) já nascem privados.
     */
    private function publicHealthInfo(Entity $entity)
    {
        // Sob emergência declarada, tudo aparece — inclusive os sempre-restritos
        // e o contato do cuidador, independente de is_public.
        if ($this->hasActiveEmergency($entity)) {
            return $entity->healthFields->pluck('field_value', 'field_key');
        }

        return $entity->healthFields
            ->where('is_public', true)
            ->where('field_key', '!=', EntityHealthField::NEVER_PUBLIC_IN_NORMAL_VIEW)
            ->pluck('field_value', 'field_key');
    }

    /**
     * Emergência ativa = declaração feita dentro da janela de validade.
     */
    private function hasActiveEmergency(Entity $entity): bool
    {
        return EntityEmergencyDeclaration::where('entity_id', $entity->id)
            ->where('declared_at', '>', now()->subHours(EntityEmergencyDeclaration::ACTIVE_WINDOW_HOURS))
            ->exists();
    }
}
