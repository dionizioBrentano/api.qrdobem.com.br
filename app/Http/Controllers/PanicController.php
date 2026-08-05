<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\PanicEvent;
use App\Models\PanicRecipient;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\Tenant;
use App\Policies\SpacePolicy;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\NotificationMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PanicController — Botão de Pânico.
 * T1-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * DECISÃO DO PROPRIETÁRIO (06/08/2026):
 * A versão rústica sai AGORA, sem esperar o WhatsApp. O frontend é
 * instalado como app (PWA) e funciona ele próprio como alarme; o backend
 * registra o acionamento e avisa a família pelos canais que existem hoje
 * (e-mail). Quando o WhatsApp entrar, é só mais um canal na ordem de
 * preferência — nenhuma linha deste controller muda.
 *
 * PRINCÍPIO QUE GOVERNA O CÓDIGO: NADA IMPEDE O ALERTA
 *   - o evento é gravado ANTES de qualquer envio;
 *   - falha de um destinatário não interrompe os outros;
 *   - falha de TODOS os envios ainda devolve 201 com o evento criado, e o
 *     app continua tocando o alarme local.
 * Numa emergência, resposta de erro que faz o app desistir é pior que
 * alerta parcial.
 *
 * ENDPOINTS
 *   POST /spaces/{space}/panic              aciona (autenticado, via app)
 *   GET  /spaces/{space}/panic              histórico
 *   POST /panic/{event}/resolve             encerra
 *   POST /entities/{unique_code}/panic      aciona por QR (público)
 */
class PanicController extends Controller
{
    /** Ordem de tentativa por destinatário. WhatsApp entra na frente depois. */
    private const CHANNEL_ORDER = ['whatsapp', 'mail'];

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /**
     * POST /spaces/{space}/panic
     * Body: latitude?, longitude?, location_accuracy?, note?, entity_id?
     */
    public function trigger(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'panic.trigger');

        $validated = $request->validate([
            'latitude'          => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'         => 'sometimes|nullable|numeric|between:-180,180',
            'location_accuracy' => 'sometimes|nullable|string|max:50',
            'note'              => 'sometimes|nullable|string|max:500',
            'entity_id'         => 'sometimes|nullable|integer',
        ]);

        $event = $this->createEvent(
            $space,
            $validated,
            PanicEvent::SOURCE_APP,
            $request->tenant
        );

        $results = $this->notifyFamily($space, $event, $request->tenant?->name);

        return response()->json([
            'message'    => 'Alerta acionado.',
            'event_id'   => $event->id,
            'notified'   => collect($results)->where('success', true)->count(),
            'failed'     => collect($results)->where('success', false)->count(),
            'recipients' => $results,
        ], 201);
    }

    /**
     * POST /entities/{unique_code}/panic  — PÚBLICO
     *
     * Quem encontrou a pessoa na rua lê o QR e aciona. Sem autenticação,
     * por definição: exigir login aqui inviabilizaria o caso de uso.
     * Protegido por throttle na rota.
     */
    public function triggerPublic(Request $request, $unique_code)
    {
        $entity = Entity::where('unique_code', $unique_code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();

        if (!$entity || !$entity->space_id) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $space = Space::find($entity->space_id);

        if (!$space) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $validated = $request->validate([
            'latitude'          => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'         => 'sometimes|nullable|numeric|between:-180,180',
            'location_accuracy' => 'sometimes|nullable|string|max:50',
            'note'              => 'sometimes|nullable|string|max:500',
        ]);

        $validated['entity_id'] = $entity->id;

        $event = $this->createEvent($space, $validated, PanicEvent::SOURCE_QR, null);

        $results = $this->notifyFamily($space, $event, 'alguém que leu o QR Code');

        // A resposta pública não revela quem foi avisado nem quantos são:
        // isso mapearia a família para um estranho.
        return response()->json([
            'message'  => 'Alerta enviado à família. Se houver risco de vida, ligue 192 (SAMU) ou 190 (Polícia).',
            'event_id' => $event->id,
            'notified' => collect($results)->where('success', true)->isNotEmpty(),
        ], 201);
    }

    /** GET /spaces/{space}/panic */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $events = PanicEvent::with('recipients')
            ->where('space_id', $space->id)
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get();

        return response()->json([
            'events' => $events->map(fn (PanicEvent $e) => [
                'id'           => $e->id,
                'source'       => $e->source,
                'status'       => $e->status,
                'triggered_at' => $e->triggered_at,
                'resolved_at'  => $e->resolved_at,
                'note'         => $e->note,
                'maps_url'     => $e->mapsUrl(),
                'notified'     => $e->recipients->where('status', 'sent')->count(),
                'failed'       => $e->recipients->where('status', 'failed')->count(),
            ])->values(),
        ]);
    }

    /** POST /panic/{event}/resolve  { false_alarm? } */
    public function resolve(Request $request, $eventId)
    {
        $event = PanicEvent::find($eventId);

        if (!$event) {
            return response()->json(['error' => 'Evento não encontrado.'], 404);
        }

        $space = Space::find($event->space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'panic.configure');

        $event->update([
            'status'      => $request->boolean('false_alarm') ? 'false_alarm' : 'resolved',
            'resolved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Alerta encerrado.',
            'status'  => $event->status,
        ]);
    }

    /**
     * Cria o registro do acionamento.
     * Feito antes de qualquer envio, de propósito: mesmo que toda a
     * notificação falhe, fica o registro de que alguém pediu socorro.
     */
    private function createEvent(Space $space, array $data, string $source, ?Tenant $tenant): PanicEvent
    {
        return PanicEvent::create([
            'space_id'               => $space->id,
            'entity_id'              => $data['entity_id'] ?? null,
            'triggered_by_tenant_id' => $tenant?->id,
            'source'                 => $source,
            'status'                 => 'open',
            'latitude'               => $data['latitude'] ?? null,
            'longitude'              => $data['longitude'] ?? null,
            'location_accuracy'      => $data['location_accuracy'] ?? null,
            'note'                   => $data['note'] ?? null,
            'triggered_at'           => now(),
        ]);
    }

    /**
     * Avisa todos os membros do espaço, menos quem acionou.
     *
     * O disparo é síncrono nesta versão rústica. Com a fila configurada
     * (`queue:batch` no cron), o passo natural é mover para job — mas
     * síncrono agora entrega o alerta hoje, e alerta que chega hoje vale
     * mais que arquitetura que chega mês que vem.
     *
     * @return array<int, array>
     */
    private function notifyFamily(Space $space, PanicEvent $event, ?string $triggeredByName): array
    {
        $memberTenantIds = SpaceMember::where('space_id', $space->id)
            ->whereNotNull('accepted_at')
            ->pluck('tenant_id')
            ->push($space->owner_tenant_id)
            ->unique()
            ->reject(fn ($id) => $id === $event->triggered_by_tenant_id)
            ->values();

        $members = Tenant::whereIn('id', $memberTenantIds)->get();

        if ($members->isEmpty()) {
            Log::warning('PanicController: nenhum destinatário no espaço', ['space_id' => $space->id]);
            return [];
        }

        $message = $this->buildMessage($space, $event, $triggeredByName);

        $results = [];

        foreach ($members as $member) {
            $destinations = array_filter([
                'mail'     => $member->email,
                'whatsapp' => $member->phone, // ignorado até o canal existir
            ]);

            if (empty($destinations)) {
                continue;
            }

            $result = $this->dispatcher->sendVia($destinations, self::CHANNEL_ORDER, $message);

            PanicRecipient::create([
                'panic_event_id' => $event->id,
                'tenant_id'      => $member->id,
                'channel'        => $result->channel,
                'destination'    => $result->to,
                'status'         => $result->success ? 'sent' : 'failed',
                'provider_id'    => $result->providerId,
                'error'          => $result->error,
                'sent_at'        => $result->success ? now() : null,
            ]);

            $results[] = [
                'tenant_id' => $member->id,
                'name'      => $member->name,
                'channel'   => $result->channel,
                'success'   => $result->success,
            ];
        }

        return $results;
    }

    private function buildMessage(Space $space, PanicEvent $event, ?string $triggeredByName): NotificationMessage
    {
        $who = $triggeredByName ?: 'um membro da família';
        $when = $event->triggered_at->format('d/m/Y H:i');

        $body = "ALERTA DE EMERGÊNCIA\n\n"
            . "Acionado por: {$who}\n"
            . "Espaço: {$space->name}\n"
            . "Horário: {$when}\n";

        if ($event->note) {
            $body .= "Observação: {$event->note}\n";
        }

        if ($maps = $event->mapsUrl()) {
            $body .= "\nLocalização: {$maps}\n";
        } else {
            $body .= "\nLocalização não informada.\n";
        }

        $body .= "\nSe houver risco de vida, ligue 192 (SAMU) ou 190 (Polícia).";

        return new NotificationMessage(
            subject: 'ALERTA DE EMERGÊNCIA — QR do Bem',
            body: $body,
            // Template WhatsApp já nomeado: quando o canal existir, não é
            // preciso voltar aqui para inventar o nome.
            template: 'panic_alert',
            templateData: [$who, $space->name, $when, $event->mapsUrl() ?? 'não informada'],
            url: $event->mapsUrl(),
            priority: 'urgent',
        );
    }
}
