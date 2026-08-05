<?php

namespace App\Http\Controllers;

use App\Models\HeatmapCell;
use Illuminate\Http\Request;

/**
 * HeatmapController — mapa de calor público das leituras.
 * Fase 6, T2-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * Expõe onde há maior volume de leituras de QR, por tipo de ocorrência.
 * Sem exigência de k-anonimato ou defasagem temporal — decisão do
 * proprietário em 05/08/2026.
 *
 * Rota pública: o mapa é material de campanha e serve para ser visto por
 * quem ainda não é usuário.
 *
 * ENDPOINTS
 *   GET /heatmap                 células agregadas
 *   GET /heatmap/summary         totais por tipo
 */
class HeatmapController extends Controller
{
    /**
     * GET /heatmap
     * Filtros: ?type=person|pet|object &bounds=lat1,lng1,lat2,lng2
     *
     * O recorte por `bounds` existe para o mapa carregar só a área visível.
     * Sem ele, um mapa nacional traria todas as células a cada movimento de
     * pan — e o volume cresce com o uso do sistema.
     */
    public function index(Request $request)
    {
        $query = HeatmapCell::query();

        if ($type = $request->input('type')) {
            $query->where('entity_type', $type);
        }

        if ($bounds = $request->input('bounds')) {
            $parts = array_map('floatval', explode(',', $bounds));

            if (count($parts) === 4) {
                [$lat1, $lng1, $lat2, $lng2] = $parts;

                // min/max porque o cliente pode mandar os cantos em
                // qualquer ordem, dependendo de como arrastou o mapa.
                $query->whereBetween('cell_lat', [min($lat1, $lat2), max($lat1, $lat2)])
                      ->whereBetween('cell_lng', [min($lng1, $lng2), max($lng1, $lng2)]);
            }
        }

        $cells = $query->orderByDesc('reads_count')->limit(5000)->get();

        return response()->json([
            'cells' => $cells->map(fn (HeatmapCell $c) => [
                'lat'    => (float) $c->cell_lat,
                'lng'    => (float) $c->cell_lng,
                'type'   => $c->entity_type,
                'weight' => $c->reads_count,
                'last'   => $c->last_read_at?->toDateString(),
            ])->values(),
            'meta' => [
                'count' => $cells->count(),
                // Resolução declarada: quem consome o mapa precisa saber
                // que o ponto é uma célula, não um endereço.
                'cell_size_km' => 1.1,
                'max_weight'   => $cells->max('reads_count') ?? 0,
            ],
        ]);
    }

    /** GET /heatmap/summary */
    public function summary()
    {
        $rows = HeatmapCell::selectRaw('entity_type, SUM(reads_count) as total, COUNT(*) as cells')
            ->groupBy('entity_type')
            ->get();

        return response()->json([
            'summary' => $rows->map(fn ($r) => [
                'type'  => $r->entity_type,
                'reads' => (int) $r->total,
                'cells' => (int) $r->cells,
            ])->values(),
        ]);
    }
}
