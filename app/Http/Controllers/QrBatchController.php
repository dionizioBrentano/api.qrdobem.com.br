<?php

namespace App\Http\Controllers;

use App\Models\CreditBatch;
use App\Models\Entity;
use App\Models\QrPrintBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;
use App\Services\QrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * QrBatchController — geração, gestão e impressão de QR Codes em lote.
 * Fase 3, T2-R03 do PLANO_TRILHAS_2026-08.md.
 *
 * PARA QUE SERVE
 * Uma causa que vai fazer campanha precisa de 200 etiquetas. Cadastrar uma
 * por uma no formulário é inviável — por isso o lote gera os códigos de
 * uma vez e devolve uma folha pronta para imprimir.
 *
 * DIFERENÇA ENTRE OS DOIS "LOTES" DO SISTEMA, que têm nomes parecidos:
 *   - `credit_batches`  → lote de CRÉDITO comprado (quantos QRs você pode criar)
 *   - `qr_print_batches`→ lote de IMPRESSÃO (os QRs efetivamente gerados)
 * Este controller consome o primeiro para produzir o segundo.
 *
 * AS ENTIDADES NASCEM SEM DONO DEFINIDO
 * Cada código do lote vira uma Entity com `status = 'pending_term'`: a
 * etiqueta existe e pode ser colada, mas a página pública só abre depois
 * que alguém assume a responsabilidade e aceita o termo. Gerar já ativo
 * criaria centenas de páginas públicas sem responsável — exatamente o que
 * a arquitetura de termos existe para impedir.
 *
 * ENDPOINTS
 *   POST /spaces/{space}/qr-batches            cria o lote
 *   GET  /spaces/{space}/qr-batches            lista
 *   GET  /qr-batches/{batch}                   detalhe com os códigos
 *   GET  /qr-batches/{batch}/print             folha de impressão (HTML A4)
 */
class QrBatchController extends Controller
{
    public function __construct(private QrCodeService $qrCode)
    {
    }

    /** POST /spaces/{space}/qr-batches  { quantity, label? } */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.create');

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:' . QrPrintBatch::MAX_QUANTITY,
            'label'    => 'sometimes|nullable|string|max:255',
        ]);

        $quantity = (int) $validated['quantity'];

        if (!$space->organization_id) {
            return response()->json([
                'error' => 'A geração em lote exige que o espaço esteja vinculado a uma organização.',
                'code'  => 'ORGANIZATION_REQUIRED_FOR_BATCH',
            ], 402);
        }

        // Crédito continua pendurado na organização nesta fase.
        $creditBatch = CreditBatch::where('organization_id', $space->organization_id)
            ->where('status', 'active')
            ->where('amount_available', '>=', $quantity)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('expires_at')
            ->first();

        if (!$creditBatch) {
            return response()->json([
                'error' => "Créditos da organização insuficientes para gerar {$quantity} QR Codes.",
                'code'  => 'INSUFFICIENT_CREDITS',
            ], 402);
        }

        $batch = DB::transaction(function () use ($space, $request, $validated, $quantity, $creditBatch) {
            $batch = QrPrintBatch::create([
                'space_id'             => $space->id,
                'created_by_tenant_id' => $request->tenant->id,
                'label'                => $validated['label'] ?? null,
                'quantity'             => $quantity,
                'status'               => 'generating',
            ]);

            $codes = [];

            for ($i = 0; $i < $quantity; $i++) {
                $uniqueCode = (string) Str::uuid();

                Entity::create([
                    'organization_id' => $space->organization_id,
                    'space_id'        => $space->id,
                    'credit_batch_id' => $creditBatch->id,
                    'unique_code'     => $uniqueCode,
                    'type'            => 'object',
                    // Nome provisório: a etiqueta ainda não foi atribuída.
                    'encrypted_name'  => ($validated['label'] ?? 'Lote') . ' #' . ($i + 1),
                    'encrypted_contact_phone' => '',
                    'is_active'       => true,
                    // Ver a justificativa no cabeçalho da classe.
                    'status'          => 'pending_term',
                ]);

                $codes[] = [
                    'code' => $uniqueCode,
                    'url'  => $this->qrCode->urlFor($uniqueCode),
                ];
            }

            // Desconto de uma vez só: decrementar dentro do laço faria N
            // escritas na mesma linha, com risco de corrida.
            $creditBatch->decrement('amount_available', $quantity);

            if ($creditBatch->fresh()->amount_available <= 0) {
                $creditBatch->update(['status' => 'exhausted']);
            }

            $batch->update([
                'codes'        => $codes,
                'status'       => 'ready',
                'generated_at' => now(),
            ]);

            return $batch->fresh();
        });

        return response()->json([
            'message'   => "{$quantity} QR Codes gerados.",
            'batch'     => [
                'id'       => $batch->id,
                'label'    => $batch->label,
                'quantity' => $batch->quantity,
                'status'   => $batch->status,
            ],
            'print_url' => url("/api/qr-batches/{$batch->id}/print"),
        ], 201);
    }

    /** GET /spaces/{space}/qr-batches */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $batches = QrPrintBatch::where('space_id', $space->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'batches' => $batches->map(fn (QrPrintBatch $b) => [
                'id'           => $b->id,
                'label'        => $b->label,
                'quantity'     => $b->quantity,
                'status'       => $b->status,
                'generated_at' => $b->generated_at,
                'print_url'    => url("/api/qr-batches/{$b->id}/print"),
            ])->values(),
        ]);
    }

    /** GET /qr-batches/{batch} */
    public function show(Request $request, $batchId)
    {
        $batch = QrPrintBatch::find($batchId);

        if (!$batch) {
            return response()->json(['error' => 'Lote não encontrado.'], 404);
        }

        $space = Space::find($batch->space_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        return response()->json([
            'batch' => [
                'id'           => $batch->id,
                'label'        => $batch->label,
                'quantity'     => $batch->quantity,
                'status'       => $batch->status,
                'generated_at' => $batch->generated_at,
                'codes'        => $batch->codes,
            ],
        ]);
    }

    /**
     * GET /qr-batches/{batch}/print
     *
     * Folha A4 em HTML, pronta para Ctrl+P. HTML e não PDF de propósito:
     * gerar PDF exigiria dependência nova (dompdf/mpdf), e `composer
     * require` em CPanel é ponto recorrente de falha de deploy. O navegador
     * já imprime HTML em PDF, com o mesmo resultado prático.
     *
     * Layout: 3 colunas x 8 linhas = 24 etiquetas por folha, com margem de
     * corte. O SVG é embutido — a folha funciona offline depois de salva.
     */
    public function print(Request $request, $batchId)
    {
        $batch = QrPrintBatch::find($batchId);

        if (!$batch || !$batch->isReady()) {
            return response('Lote não encontrado ou ainda em geração.', 404);
        }

        $space = Space::find($batch->space_id);

        if (!$space) {
            return response('Espaço não encontrado.', 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $cards = '';

        foreach (($batch->codes ?? []) as $index => $item) {
            $svg = $this->qrCode->svgFor($item['code'], 220);

            if ($svg === null) {
                continue;
            }

            $number = $index + 1;
            $short  = substr($item['code'], 0, 8);

            $cards .= <<<HTML
            <div class="card">
                <div class="qr">{$svg}</div>
                <div class="code">{$short}</div>
                <div class="num">#{$number}</div>
            </div>
            HTML;
        }

        $title = e($batch->label ?: ('Lote ' . $batch->id));
        $spaceName = e($space->name);
        $generated = $batch->generated_at?->format('d/m/Y H:i') ?? '';

        $html = <<<HTML
        <!doctype html>
        <html lang="pt-BR">
        <head>
        <meta charset="utf-8">
        <title>QR do Bem — {$title}</title>
        <style>
            /* Margem de 10mm: abaixo disso a maioria das impressoras
               domésticas corta o conteúdo da borda. */
            @page { size: A4; margin: 10mm; }
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; margin: 0; }
            header { margin-bottom: 6mm; }
            h1 { font-size: 14pt; margin: 0 0 2mm; }
            .meta { font-size: 9pt; color: #555; }
            .sheet {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 4mm;
            }
            .card {
                border: 1px dashed #bbb;   /* guia de corte */
                padding: 3mm;
                text-align: center;
                /* Impede que uma etiqueta seja partida entre duas folhas. */
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .qr svg { width: 100%; height: auto; display: block; }
            .code { font-family: monospace; font-size: 8pt; color: #333; margin-top: 1mm; }
            .num  { font-size: 7pt; color: #999; }
            .noprint { margin-bottom: 5mm; }
            @media print { .noprint { display: none; } }
        </style>
        </head>
        <body>
            <div class="noprint">
                <button onclick="window.print()">Imprimir</button>
            </div>
            <header>
                <h1>{$title}</h1>
                <div class="meta">{$spaceName} &middot; {$batch->quantity} etiquetas &middot; gerado em {$generated}</div>
            </header>
            <div class="sheet">{$cards}</div>
        </body>
        </html>
        HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
