<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Models\HealthDiaryEntry;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\Space;
use App\Policies\SpacePolicy;
use App\Services\IcsCalendarService;
use App\Services\MedicationLookupService;
use Illuminate\Http\Request;

/**
 * HealthController — módulo Premium de Saúde.
 * Fase 6, T1-R08 a T1-R11 do PLANO_TRILHAS_2026-08.md.
 *
 * ENDPOINTS
 *   GET  /entities/{code}/health              diário + prescrições
 *   POST /entities/{code}/health/diary        registra no diário
 *   POST /entities/{code}/prescriptions       cria prescrição
 *   PUT  /prescriptions/{id}                  edita
 *   GET  /prescriptions/{id}/calendar.ics     exporta para a agenda
 *   POST /medications/lookup                  código de barras → produto
 *   POST /medications/{id}/confirm            confirma ou corrige
 *
 * O PRINCÍPIO QUE GOVERNA O MÓDULO
 * O sistema SUGERE horários a partir da bula e da prescrição informada,
 * EXIBE de onde tirou, e EXIGE confirmação. Nunca decide sozinho. A bula é
 * documento em texto, não API de posologia — e um erro de interpretação
 * aqui tem consequência real na saúde de alguém.
 */
class HealthController extends Controller
{
    public function __construct(
        private MedicationLookupService $lookup,
        private IcsCalendarService $calendar
    ) {
    }

    /** GET /entities/{code}/health */
    public function show(Request $request, $uniqueCode)
    {
        $entity = $this->authorizedEntity($request, $uniqueCode, 'entity.view');

        if (!$entity instanceof Entity) {
            return $entity; // resposta de erro
        }

        $prescriptions = Prescription::with('medication')
            ->where('entity_id', $entity->id)
            ->where('is_active', true)
            ->get();

        $diary = HealthDiaryEntry::where('entity_id', $entity->id)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return response()->json([
            'entity' => [
                'code' => $entity->unique_code,
                'name' => $entity->encrypted_name,
                'type' => $entity->type,
            ],
            'prescriptions' => $prescriptions->map(fn (Prescription $p) => [
                'id'              => $p->id,
                'medication_name' => $p->medication_name,
                'dosage'          => $p->dosage,
                'interval_hours'  => $p->interval_hours,
                'schedule_times'  => $p->schedule_times ?: $p->calculateSchedule(),
                'is_continuous'   => $p->isContinuous(),
                'ends_on'         => $p->ends_on,
                // A tela usa isto para exibir "sugerido pela bula" junto do
                // horário — a sugestão nunca aparece como se fosse decisão
                // do sistema.
                'suggested_from_leaflet' => $p->suggested_from_leaflet,
                'calendar_url'    => url("/api/prescriptions/{$p->id}/calendar.ics"),
            ])->values(),
            'diary' => $diary->map(fn (HealthDiaryEntry $d) => [
                'id'            => $d->id,
                'kind'          => $d->kind,
                'kind_label'    => $d->kindLabel(),
                'title'         => $d->title,
                'description'   => $d->description,
                'measure_key'   => $d->measure_key,
                'measure_value' => $d->measure_value,
                'occurred_at'   => $d->occurred_at,
            ])->values(),
            'diary_kinds' => HealthDiaryEntry::KIND_LABELS,
        ]);
    }

    /** POST /entities/{code}/health/diary */
    public function storeDiaryEntry(Request $request, $uniqueCode)
    {
        $entity = $this->authorizedEntity($request, $uniqueCode, 'entity.edit');

        if (!$entity instanceof Entity) {
            return $entity;
        }

        $validated = $request->validate([
            'kind'            => 'required|string|in:' . implode(',', HealthDiaryEntry::KINDS),
            'title'           => 'required|string|max:255',
            'description'     => 'sometimes|nullable|string|max:2000',
            'measure_key'     => 'sometimes|nullable|string|max:40',
            'measure_value'   => 'sometimes|nullable|string|max:60',
            'prescription_id' => 'sometimes|nullable|integer',
            'occurred_at'     => 'sometimes|nullable|date',
        ]);

        $entry = HealthDiaryEntry::create([
            'entity_id'            => $entity->id,
            'created_by_tenant_id' => $request->tenant->id,
            'kind'                 => $validated['kind'],
            'title'                => $validated['title'],
            'description'          => $validated['description'] ?? null,
            'measure_key'          => $validated['measure_key'] ?? null,
            'measure_value'        => $validated['measure_value'] ?? null,
            'prescription_id'      => $validated['prescription_id'] ?? null,
            // Sem data informada, é agora: o caso comum é registrar o que
            // acabou de acontecer.
            'occurred_at'          => $validated['occurred_at'] ?? now(),
        ]);

        return response()->json([
            'message' => 'Registro adicionado.',
            'entry'   => ['id' => $entry->id, 'occurred_at' => $entry->occurred_at],
        ], 201);
    }

    /**
     * POST /entities/{code}/prescriptions
     * Body: medication_name, medication_id?, dosage?, interval_hours?, first_dose_at?...
     */
    public function storePrescription(Request $request, $uniqueCode)
    {
        $entity = $this->authorizedEntity($request, $uniqueCode, 'entity.edit');

        if (!$entity instanceof Entity) {
            return $entity;
        }

        $validated = $request->validate([
            'medication_name' => 'required|string|max:255',
            'medication_id'   => 'sometimes|nullable|integer',
            'dosage'          => 'sometimes|nullable|string|max:120',
            'interval_hours'  => 'sometimes|nullable|integer|min:1|max:168',
            'first_dose_at'   => 'sometimes|nullable|date_format:H:i',
            'starts_on'       => 'sometimes|nullable|date',
            'ends_on'         => 'sometimes|nullable|date|after_or_equal:starts_on',
            'notes'           => 'sometimes|nullable|string|max:1000',
            'prescriber'      => 'sometimes|nullable|string|max:255',
        ]);

        $suggestedFromLeaflet = false;
        $intervalHours = $validated['interval_hours'] ?? null;

        // Sem intervalo informado, tenta a bula — como SUGESTÃO. O campo
        // `suggested_from_leaflet` faz a tela exibir a fonte, e o usuário
        // confirma ou corrige antes de valer.
        if (!$intervalHours && !empty($validated['medication_id'])) {
            $medication = Medication::find($validated['medication_id']);

            if ($medication) {
                $leaflet = $this->lookup->fetchLeaflet($medication);
                $guess = $leaflet?->guessIntervalHours();

                if ($guess) {
                    $intervalHours = $guess;
                    $suggestedFromLeaflet = true;
                }
            }
        }

        $prescription = Prescription::create([
            'entity_id'              => $entity->id,
            'medication_id'          => $validated['medication_id'] ?? null,
            'medication_name'        => $validated['medication_name'],
            'dosage'                 => $validated['dosage'] ?? null,
            'interval_hours'         => $intervalHours,
            'first_dose_at'          => $validated['first_dose_at'] ?? null,
            'starts_on'              => $validated['starts_on'] ?? now()->toDateString(),
            'ends_on'                => $validated['ends_on'] ?? null,
            'notes'                  => $validated['notes'] ?? null,
            'prescriber'             => $validated['prescriber'] ?? null,
            'suggested_from_leaflet' => $suggestedFromLeaflet,
            'is_active'              => true,
        ]);

        // Horários calculados e gravados: o .ics não recalcula a cada
        // exportação, e um ajuste manual do usuário não se perde depois.
        $prescription->update(['schedule_times' => $prescription->calculateSchedule()]);

        return response()->json([
            'message'      => 'Prescrição criada.',
            'prescription' => [
                'id'             => $prescription->id,
                'schedule_times' => $prescription->schedule_times,
                'suggested_from_leaflet' => $suggestedFromLeaflet,
            ],
            'warning' => $suggestedFromLeaflet
                ? 'Os horários foram sugeridos a partir da bula. Confira com quem prescreveu antes de seguir.'
                : null,
        ], 201);
    }

    /** PUT /prescriptions/{id} */
    public function updatePrescription(Request $request, $prescriptionId)
    {
        $prescription = Prescription::find($prescriptionId);

        if (!$prescription) {
            return response()->json(['error' => 'Prescrição não encontrada.'], 404);
        }

        $entity = Entity::find($prescription->entity_id);
        $check = $this->authorizeEntity($request, $entity, 'entity.edit');

        if ($check !== null) {
            return $check;
        }

        $validated = $request->validate([
            'dosage'         => 'sometimes|nullable|string|max:120',
            'interval_hours' => 'sometimes|nullable|integer|min:1|max:168',
            'first_dose_at'  => 'sometimes|nullable|date_format:H:i',
            'ends_on'        => 'sometimes|nullable|date',
            'notes'          => 'sometimes|nullable|string|max:1000',
            'schedule_times' => 'sometimes|array',
            'is_active'      => 'sometimes|boolean',
        ]);

        $prescription->update($validated);

        // Horário ajustado à mão vence o cálculo: se o usuário mexeu, ele
        // tem razão sobre a rotina dele.
        if (!array_key_exists('schedule_times', $validated)) {
            $prescription->update(['schedule_times' => $prescription->calculateSchedule()]);
        }

        return response()->json([
            'message'      => 'Prescrição atualizada.',
            'prescription' => $prescription->fresh(),
        ]);
    }

    /**
     * GET /prescriptions/{id}/calendar.ics
     * Exporta para a agenda nativa (T1-R11).
     */
    public function calendar(Request $request, $prescriptionId)
    {
        $prescription = Prescription::find($prescriptionId);

        if (!$prescription) {
            return response('Prescrição não encontrada.', 404);
        }

        $entity = Entity::find($prescription->entity_id);
        $check = $this->authorizeEntity($request, $entity, 'entity.view');

        if ($check !== null) {
            return $check;
        }

        $ics = $this->calendar->forPrescription($prescription);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="medicacao-' . $prescription->id . '.ics"',
        ]);
    }

    /**
     * POST /medications/lookup  { ean }
     * Código de barras → produto (T1-R09).
     */
    public function lookupMedication(Request $request)
    {
        $validated = $request->validate([
            'ean' => 'required|string|max:20',
        ]);

        $result = $this->lookup->resolve($validated['ean']);
        $medication = $result['medication'];

        return response()->json([
            'medication' => [
                'id'                => $medication->id,
                'ean'               => $medication->ean,
                'name'              => $medication->name,
                'presentation'      => $medication->presentation,
                'laboratory'        => $medication->laboratory,
                'active_ingredient' => $medication->active_ingredient,
                'status'            => $medication->status,
                'confirmations'     => $medication->confirmations_count,
            ],
            // A tela usa isto para decidir se pergunta "é este o produto?".
            'needs_confirmation' => $result['needs_confirmation'],
            'from_cache'         => $result['from_cache'],
            'question' => $result['needs_confirmation']
                ? 'Este é o produto que você comprou?'
                : null,
        ]);
    }

    /**
     * POST /medications/{id}/confirm  { is_correct, correction? }
     * O voto que constrói a base (T1-R09).
     */
    public function confirmMedication(Request $request, $medicationId)
    {
        $medication = Medication::find($medicationId);

        if (!$medication) {
            return response()->json(['error' => 'Medicamento não encontrado.'], 404);
        }

        $validated = $request->validate([
            'is_correct'              => 'required|boolean',
            'correction'              => 'sometimes|array',
            'correction.name'         => 'sometimes|string|max:255',
            'correction.presentation' => 'sometimes|nullable|string|max:255',
            'correction.laboratory'   => 'sometimes|nullable|string|max:255',
        ]);

        $updated = $this->lookup->confirm(
            $medication,
            $request->tenant,
            $validated['is_correct'],
            $validated['correction'] ?? []
        );

        return response()->json([
            'message' => $validated['is_correct'] ? 'Obrigado por confirmar.' : 'Obrigado pela correção.',
            'medication' => [
                'id'            => $updated->id,
                'name'          => $updated->name,
                'status'        => $updated->status,
                'confirmations' => $updated->confirmations_count,
            ],
        ]);
    }

    /**
     * Busca a entidade e verifica a permissão no espaço dela.
     * Devolve a Entity ou uma resposta de erro.
     */
    private function authorizedEntity(Request $request, string $uniqueCode, string $permission)
    {
        $entity = Entity::where('unique_code', $uniqueCode)->first();

        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $check = $this->authorizeEntity($request, $entity, $permission);

        return $check ?? $entity;
    }

    /**
     * Verificação de permissão. Devolve null quando está liberado.
     *
     * Aceita o caminho antigo (pertencer à organização) além do espaço:
     * entidade que o backfill ainda não ligou continua acessível ao dono.
     */
    private function authorizeEntity(Request $request, ?Entity $entity, string $permission)
    {
        if (!$entity) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $tenant = $request->tenant;

        $orgIds = $tenant->organizations()->pluck('organizations.id')->all();

        if (in_array($entity->organization_id, $orgIds, true)) {
            return null;
        }

        if ($entity->space_id) {
            $space = Space::find($entity->space_id);

            if ($space && app(SpacePolicy::class)->check($tenant, $space, $permission)) {
                return null;
            }
        }

        return response()->json(['error' => 'Acesso negado.'], 403);
    }
}
