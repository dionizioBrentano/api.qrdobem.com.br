<?php

namespace App\Http\Controllers;

use App\Models\CauseProfile;
use App\Models\MediaItem;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CauseController — vitrine pública da causa e prestação de contas.
 * Fase 3, T2-R04 e T2-R05 do PLANO_TRILHAS_2026-08.md.
 *
 * A finalidade da trilha é arrecadação COM prova social: o menu serve como
 * vitrine dos resultados obtidos com as doações. Por isso a vitrine
 * pública sempre mostra os dois lados — o quanto entrou e o que foi feito
 * com o dinheiro.
 *
 * ENDPOINTS PÚBLICOS
 *   GET /causes              lista causas publicadas
 *   GET /causes/{slug}       vitrine de uma causa
 *
 * ENDPOINTS AUTENTICADOS
 *   PUT  /spaces/{space}/cause          edita a vitrine
 *   POST /spaces/{space}/cause/publish  publica ou despublica
 */
class CauseController extends Controller
{
    /**
     * GET /causes  — PÚBLICO
     * Filtros: ?category= &state= &q=
     */
    public function index(Request $request)
    {
        $query = CauseProfile::with('space')
            ->where('is_published', true);

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($state = $request->input('state')) {
            $query->where('state', strtoupper($state));
        }

        if ($term = $request->input('q')) {
            // Busca simples por headline. Sem full-text: em hospedagem
            // compartilhada o índice full-text é imprevisível, e o volume
            // aqui não justifica.
            $query->where('headline', 'like', '%' . $term . '%');
        }

        $causes = $query->orderByDesc('raised_amount')->limit(60)->get();

        return response()->json([
            'causes' => $causes->map(fn (CauseProfile $c) => [
                'slug'          => $c->space?->slug,
                'name'          => $c->space?->name,
                'headline'      => $c->headline,
                'category'      => $c->category,
                'city'          => $c->city,
                'state'         => $c->state,
                'goal_amount'   => $c->goal_amount,
                'raised_amount' => $c->raised_amount,
                'progress'      => $c->progressPercent(),
            ])->values(),
        ]);
    }

    /**
     * GET /causes/{slug}  — PÚBLICO
     * A vitrine: história, números e prova social aprovada.
     */
    public function show(Request $request, $slug)
    {
        $space = Space::with('causeProfile')
            ->where('slug', $slug)
            ->where('type', Space::TYPE_CAUSE)
            ->where('status', 'active')
            ->first();

        $cause = $space?->causeProfile;

        // Causa despublicada some inteiramente — nem confirma que existe.
        if (!$cause || !$cause->is_published) {
            return response()->json(['error' => 'Causa não encontrada.'], 404);
        }

        // Só mídia APROVADA aparece. Ver a regra de moderação em MediaItem.
        $media = MediaItem::where('owner_type', MediaItem::OWNER_SPACE)
            ->where('owner_id', $space->id)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        return response()->json([
            'cause' => [
                'slug'           => $space->slug,
                'name'           => $space->name,
                'headline'       => $cause->headline,
                'story'          => $cause->story,
                'category'       => $cause->category,
                'city'           => $cause->city,
                'state'          => $cause->state,
                'goal_amount'    => $cause->goal_amount,
                'raised_amount'  => $cause->raised_amount,
                'progress'       => $cause->progressPercent(),
                // Prestação de contas em texto: a prova social escrita.
                'accountability' => $cause->accountability,
            ],
            // Guarda-chuva: o doador precisa saber por qual entidade o
            // recibo sai, porque é dela que vem a dedutibilidade.
            'umbrella' => $space->parent_space_id
                ? optional(Space::find($space->parent_space_id))->only(['name', 'slug'])
                : null,
            'media' => $media->map(fn (MediaItem $m) => [
                'id'       => $m->id,
                'caption'  => $m->caption,
                'is_video' => $m->isVideo(),
                // URL assinada e temporária: caminho direto adivinhável
                // vazaria mídia reprovada do mesmo diretório.
                'url'      => $this->temporaryUrl($m),
            ])->values(),
        ]);
    }

    /** PUT /spaces/{space}/cause */
    public function update(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $request->validate([
            'headline'       => 'sometimes|string|max:255',
            'story'          => 'sometimes|nullable|string|max:5000',
            'category'       => 'sometimes|nullable|string|max:50',
            'city'           => 'sometimes|nullable|string|max:120',
            'state'          => 'sometimes|nullable|string|size:2',
            'goal_amount'    => 'sometimes|nullable|numeric|min:0',
            'accountability' => 'sometimes|nullable|string|max:10000',
        ]);

        $cause = CauseProfile::firstOrCreate(
            ['space_id' => $space->id],
            ['headline' => $space->name]
        );

        $cause->update($validated);

        return response()->json([
            'message' => 'Vitrine atualizada.',
            'cause'   => $cause->fresh(),
        ]);
    }

    /**
     * POST /spaces/{space}/cause/publish  { publish: true|false }
     *
     * Publicar exige headline e história preenchidas: causa sem história
     * contada na vitrine pública não convence ninguém a doar, e ocupa
     * espaço na listagem de quem se deu ao trabalho.
     */
    public function publish(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $cause = CauseProfile::where('space_id', $space->id)->first();

        if (!$cause) {
            return response()->json(['error' => 'Vitrine não configurada.'], 422);
        }

        $publish = $request->boolean('publish', true);

        if ($publish && (empty($cause->headline) || empty($cause->story))) {
            return response()->json([
                'error'   => 'Preencha a chamada e a história antes de publicar.',
                'code'    => 'INCOMPLETE_SHOWCASE',
                'missing' => array_values(array_filter([
                    empty($cause->headline) ? 'headline' : null,
                    empty($cause->story) ? 'story' : null,
                ])),
            ], 422);
        }

        $cause->update(['is_published' => $publish]);

        return response()->json([
            'message'      => $publish ? 'Causa publicada.' : 'Causa despublicada.',
            'is_published' => $cause->is_published,
            'public_url'   => $publish
                ? config('qrdobem.frontend_url') . '/causa/' . $space->slug
                : null,
        ]);
    }

    /**
     * URL temporária da mídia.
     *
     * O driver `local` do Laravel não assina URL. Em hospedagem
     * compartilhada é o driver em uso, então caímos para uma rota própria
     * de entrega — que verifica o status antes de servir o arquivo.
     */
    private function temporaryUrl(MediaItem $media): string
    {
        try {
            return Storage::disk('private')->temporaryUrl($media->path, now()->addMinutes(30));
        } catch (\Throwable $e) {
            return url("/api/media/{$media->id}");
        }
    }
}
