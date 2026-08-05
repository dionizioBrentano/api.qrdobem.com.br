<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\EntityEmergencyDeclaration;
use App\Models\EntityHealthField;
use App\Models\EntityObjectField;
use App\Models\AuditLog;
use App\Http\Requests\EntityStoreRequest;
use App\Models\CreditBatch;
use App\Models\HeatmapCell;
use App\Models\Organization;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\TenantTermAcceptance;
use App\Policies\SpacePolicy;
use App\Services\PiiDetector;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * EntityController — CRUD de entidades (QR Codes).
 *
 * ALTERAÇÃO DESTA VERSÃO (Fase 0, entrega 0.5 do PLANO_TRILHAS_2026-08.md):
 * o controller passa a entender ESPAÇOS (`spaces`), mantendo organização
 * como caminho válido durante toda a transição.
 *
 * ESTRATÉGIA DE TRANSIÇÃO — ler antes de mexer:
 * Este é o caminho crítico do produto em produção. Portanto:
 *
 *   1. `space_id` é ACEITO, não exigido. Quem mandar `organization_id`
 *      (todos os frontends de hoje) continua funcionando igual.
 *   2. A listagem consulta por espaço quando ele é conhecido, e cai para
 *      organização quando não é. Entidade que o backfill ainda não ligou
 *      não some da tela.
 *   3. Toda leitura de `spaces` está protegida por try/catch: se a
 *      migration 2026_08_06_000001 ainda não tiver sido aplicada no
 *      servidor, o controller opera exatamente como a versão anterior.
 *      Ordem de deploy errada não derruba o painel.
 *   4. A verificação de acesso soma os dois mundos: pertencer à
 *      organização OU ser membro do espaço.
 *
 * Quando o backfill estiver validado e os frontends mandarem `space_id`,
 * o fallback por organização pode ser removido numa entrega própria.
 */
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

        // Contexto novo: espaço. Resolvido a partir do space_id enviado ou,
        // na falta dele, do espaço vinculado à organização ativa.
        $space = $this->resolveSpace($request, $orgId);

        if (!$orgId && !$space) {
             return response()->json(['error' => 'Nenhuma organização vinculada.'], 403);
        }

        // Soma lotes da Organização.
        // Créditos continuam pendurados na organização nesta fase — mover
        // para o espaço é entrega posterior, e misturar as duas coisas
        // agora arriscaria a cota de quem está em produção.
        $activeQuota = $orgId
            ? CreditBatch::where('organization_id', $orgId)
                ->where('status', 'active')
                ->where(function($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->sum('amount_available')
            : 0;

        // Pega as entidades do contexto ativo.
        // Com espaço conhecido, busca por espaço E pela organização, para
        // não esconder entidade que o backfill ainda não ligou.
        $entities = Entity::with(['petFields', 'vaccinations', 'objectFields'])
            ->where(function ($query) use ($orgId, $space) {
                if ($space) {
                    $query->orWhere('space_id', $space->id);
                }
                if ($orgId) {
                    $query->orWhere('organization_id', $orgId);
                }
            })
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
            // Contexto de espaço. O frontend antigo simplesmente ignora
            // estes campos; o novo usa para o seletor de espaço (0.6).
            'spaces' => $this->spacesOf($tenant),
            'active_space_id' => $space?->id,
            'active_space_type' => $space?->type,
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

        $space = $this->resolveSpace($request, $orgId);

        // Permissão de espaço: quando o espaço existe, quem cria precisa ter
        // `entity.create` nele (T1-R04, delegação). Sem espaço, vale a regra
        // antiga — que continua abaixo, intacta.
        if ($space && !app(SpacePolicy::class)->check($tenant, $space, 'entity.create')) {
            return response()->json([
                'error' => 'Você não tem permissão para criar registros neste espaço.',
                'code' => 'SPACE_PERMISSION_DENIED',
            ], 403);
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
            // Nulo quando ainda não há espaço (migration não aplicada).
            // O backfill preenche depois; nada quebra no intervalo.
            'space_id' => $space?->id,
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
            'space_id' => $space?->id,
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
     * (ou é membro do espaço dela) consegue baixar. O código público em si
     * já é acessível por quem tem o link, mas não faz sentido expor um
     * gerador aberto.
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

        if (!$this->canAccessEntity($tenant, $entity)) {
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

        if (!$this->canAccessEntity($tenant, $entity)) {
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

        // Mapa de calor (Fase 6, T2-R07): agrega a leitura por célula
        // geográfica quando o navegador informou a posição.
        $this->recordHeatmap($request, $entity);

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
            // White-label e patrocínio (Fase 5, T3-R02/T3-R03).
            'branding' => $this->branding($entity),
        ]);
    }

    /**
     * Registra a leitura no mapa de calor (T2-R07).
     *
     * Aceita a localização em dois formatos, porque o frontend antigo manda
     * `location` como string e o novo manda latitude/longitude separados —
     * e reescrever o cliente antigo só por isso não se justifica.
     *
     * Envolvido em try/catch: mapa de calor é agregado estatístico. A
     * página pública de um QR de emergência não pode falhar porque a tabela
     * `heatmap_cells` ainda não existe no servidor.
     */
    private function recordHeatmap(Request $request, Entity $entity): void
    {
        try {
            $latitude  = $request->input('latitude');
            $longitude = $request->input('longitude');

            // Formato antigo: "-30.0277,-51.2287" no campo `location`.
            if ($latitude === null && $location = $request->input('location')) {
                $parts = explode(',', (string) $location);

                if (count($parts) === 2) {
                    $latitude  = trim($parts[0]);
                    $longitude = trim($parts[1]);
                }
            }

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                return;
            }

            HeatmapCell::record((float) $latitude, (float) $longitude, $entity->type);
        } catch (\Throwable $e) {
            Log::warning('EntityController: falha ao registrar mapa de calor', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Marca exibida na página pública (T3-R02, T3-R03).
     *
     * Duas origens, nesta ordem de precedência:
     *   1. o LOTE que originou o QR, quando foi patrocinado — é o modelo da
     *      farmácia que banca o código e leva a sua promoção na URL;
     *   2. as configurações do espaço, quando o próprio parceiro é o dono.
     *
     * O patrocínio vem primeiro porque é ele que foi pago para aparecer.
     *
     * Devolve null quando não há marca: a página então usa a identidade
     * padrão do QR do Bem, sem campo vazio na interface.
     */
    private function branding(Entity $entity): ?array
    {
        try {
            // 1. Patrocínio do lote de crédito.
            if ($entity->credit_batch_id) {
                $batch = CreditBatch::find($entity->credit_batch_id);

                if ($batch && $batch->sponsor_space_id) {
                    $sponsor = Space::find($batch->sponsor_space_id);

                    if ($sponsor) {
                        return [
                            'source'         => 'sponsor',
                            'name'           => $sponsor->name,
                            'logo_url'       => $sponsor->setting('branding.logo_url'),
                            'primary_color'  => $sponsor->setting('branding.primary_color'),
                            'sponsor_url'    => $batch->sponsor_url,
                            'sponsor_label'  => $sponsor->setting('branding.sponsor_label', 'Oferecido por'),
                        ];
                    }
                }
            }

            // 2. Marca do próprio espaço.
            if ($entity->space_id) {
                $space = Space::find($entity->space_id);
                $logo = $space?->setting('branding.logo_url');

                if ($logo) {
                    return [
                        'source'        => 'space',
                        'name'          => $space->name,
                        'logo_url'      => $logo,
                        'primary_color' => $space->setting('branding.primary_color'),
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            // Marca é enfeite; página de emergência não pode cair por causa
            // de uma coluna de patrocínio que ainda não existe no servidor.
            return null;
        }
    }

    /**
     * Resolve o espaço ativo da requisição.
     *
     * Ordem: `space_id` explícito → espaço da organização ativa → null.
     *
     * O try/catch existe para o intervalo entre subir este arquivo e rodar
     * a migration de `spaces` no servidor: sem tabela, o controller volta a
     * se comportar exatamente como a versão anterior, em vez de derrubar o
     * painel com erro de SQL.
     */
    private function resolveSpace(Request $request, ?int $orgId): ?Space
    {
        try {
            $spaceId = $request->input('space_id');

            if ($spaceId) {
                return Space::find($spaceId);
            }

            if ($orgId) {
                return Space::where('organization_id', $orgId)->first();
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('EntityController: spaces indisponível, usando organização', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Espaços a que esta conta tem acesso — como dona ou como membro.
     * Alimenta o seletor de espaço do painel (entrega 0.6).
     */
    private function spacesOf($tenant): array
    {
        try {
            $memberSpaceIds = SpaceMember::where('tenant_id', $tenant->id)
                ->whereNotNull('accepted_at')
                ->pluck('space_id');

            return Space::where('owner_tenant_id', $tenant->id)
                ->orWhereIn('id', $memberSpaceIds)
                ->orderBy('id')
                ->get()
                ->map(fn (Space $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'type' => $s->type,
                    'slug' => $s->slug,
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            // Mesma proteção do resolveSpace: sem tabela, lista vazia.
            return [];
        }
    }

    /**
     * Acesso à entidade: pela organização (regra atual) OU pelo espaço
     * (regra nova). Basta um dos dois — é o que mantém em pé tanto a
     * entidade já migrada quanto a que o backfill ainda não tocou.
     */
    private function canAccessEntity($tenant, Entity $entity): bool
    {
        $orgIds = $tenant->organizations()->pluck('organizations.id')->all();

        if (in_array($entity->organization_id, $orgIds)) {
            return true;
        }

        if (!$entity->space_id) {
            return false;
        }

        try {
            $space = Space::find($entity->space_id);

            return $space
                ? app(SpacePolicy::class)->check($tenant, $space, 'entity.view')
                : false;
        } catch (\Throwable $e) {
            return false;
        }
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
