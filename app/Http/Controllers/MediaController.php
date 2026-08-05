<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MediaController — upload, moderação e entrega de mídia.
 * Fase 3 (T2-R05) e base da prova social da Fase 4 (T4-R07).
 *
 * TRÊS REGRAS QUE NÃO SE NEGOCIAM
 *
 * 1. TIPO VERIFICADO PELO CONTEÚDO, não pela extensão. Um `.jpg` que na
 *    verdade é PHP, servido de um diretório executável, é execução remota.
 *    Por isso o MIME vem de `getMimeType()` (que lê o arquivo) e o nome
 *    gravado é gerado por nós, nunca o enviado pelo usuário.
 *
 * 2. STORAGE PRIVADO. O arquivo não fica em `public/`. A entrega passa por
 *    rota que confere o status — mídia reprovada continua no disco e não
 *    pode vazar por caminho adivinhável.
 *
 * 3. MODERAÇÃO ANTES DA EXPOSIÇÃO. Nasce `pending`. Foto de terceiro pode
 *    trazer rosto de menor, endereço legível ao fundo, documento sobre a
 *    mesa. Publicação automática é inaceitável aqui.
 *
 * ENDPOINTS
 *   POST   /spaces/{space}/media           envia
 *   GET    /spaces/{space}/media           lista (inclui pendentes, p/ quem modera)
 *   POST   /media/{media}/moderate         aprova ou reprova
 *   DELETE /media/{media}                  remove
 *   GET    /media/{media}                  entrega o arquivo (público se aprovado)
 */
class MediaController extends Controller
{
    /** POST /spaces/{space}/media */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.edit');

        $request->validate([
            'file'    => 'required|file|max:' . (MediaItem::MAX_SIZE_BYTES / 1024),
            'caption' => 'sometimes|nullable|string|max:500',
        ]);

        $file = $request->file('file');

        // MIME lido do conteúdo, não da extensão. Ver regra 1 no cabeçalho.
        $mime = $file->getMimeType();

        if (!in_array($mime, MediaItem::ALLOWED_MIMES, true)) {
            return response()->json([
                'error'   => 'Tipo de arquivo não aceito. Envie JPG, PNG, WEBP ou MP4.',
                'code'    => 'INVALID_MIME',
                'received' => $mime,
            ], 422);
        }

        // Nome gerado por nós: nome de arquivo enviado pelo usuário é
        // vetor de path traversal e de extensão dupla.
        $extension = $this->extensionFor($mime);
        $filename  = Str::uuid() . '.' . $extension;
        $path      = "spaces/{$space->id}/{$filename}";

        Storage::disk('private')->putFileAs("spaces/{$space->id}", $file, $filename);

        $media = MediaItem::create([
            'owner_type'            => MediaItem::OWNER_SPACE,
            'owner_id'              => $space->id,
            'uploaded_by_tenant_id' => $request->tenant->id,
            'path'                  => $path,
            'mime_type'             => $mime,
            'size_bytes'            => $file->getSize(),
            'caption'               => $request->input('caption'),
            'status'                => 'pending',
        ]);

        return response()->json([
            'message' => 'Arquivo enviado. Ficará visível depois da revisão.',
            'media'   => [
                'id'       => $media->id,
                'status'   => $media->status,
                'caption'  => $media->caption,
                'is_video' => $media->isVideo(),
            ],
        ], 201);
    }

    /** GET /spaces/{space}/media */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'entity.view');

        $media = MediaItem::where('owner_type', MediaItem::OWNER_SPACE)
            ->where('owner_id', $space->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'media' => $media->map(fn (MediaItem $m) => [
                'id'               => $m->id,
                'status'           => $m->status,
                'caption'          => $m->caption,
                'is_video'         => $m->isVideo(),
                'size_bytes'       => $m->size_bytes,
                'rejection_reason' => $m->rejection_reason,
                'url'              => url("/api/media/{$m->id}"),
                'created_at'       => $m->created_at,
            ])->values(),
            'pending_count' => $media->where('status', 'pending')->count(),
        ]);
    }

    /** POST /media/{media}/moderate  { approve: bool, reason?: string } */
    public function moderate(Request $request, $mediaId)
    {
        $media = MediaItem::find($mediaId);

        if (!$media || $media->owner_type !== MediaItem::OWNER_SPACE) {
            return response()->json(['error' => 'Mídia não encontrada.'], 404);
        }

        $space = Space::find($media->owner_id);

        if (!$space) {
            return response()->json(['error' => 'Espaço não encontrado.'], 404);
        }

        // Moderar é ato de gestão do espaço, não de quem enviou.
        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $request->validate([
            'approve' => 'required|boolean',
            'reason'  => 'sometimes|nullable|string|max:255',
        ]);

        $media->update([
            'status'                 => $validated['approve'] ? 'approved' : 'rejected',
            'moderated_by_tenant_id' => $request->tenant->id,
            'moderated_at'           => now(),
            'rejection_reason'       => $validated['approve'] ? null : ($validated['reason'] ?? null),
        ]);

        return response()->json([
            'message' => $validated['approve'] ? 'Mídia aprovada.' : 'Mídia reprovada.',
            'status'  => $media->status,
        ]);
    }

    /** DELETE /media/{media} */
    public function destroy(Request $request, $mediaId)
    {
        $media = MediaItem::find($mediaId);

        if (!$media) {
            return response()->json(['error' => 'Mídia não encontrada.'], 404);
        }

        $space = Space::find($media->owner_id);

        if ($space) {
            app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');
        }

        // Arquivo apagado do disco junto: soft delete no banco guardaria a
        // referência a um arquivo que precisa sumir por pedido de quem
        // aparece na foto.
        try {
            Storage::disk('private')->delete($media->path);
        } catch (\Throwable $e) {
            // Arquivo já ausente não impede a remoção do registro.
        }

        $media->delete();

        return response()->json(['message' => 'Mídia removida.']);
    }

    /**
     * GET /media/{media}  — entrega o arquivo.
     *
     * Rota pública, mas SÓ serve mídia aprovada. É esta verificação que
     * torna seguro guardar aprovada e reprovada no mesmo diretório.
     */
    public function serve(Request $request, $mediaId)
    {
        $media = MediaItem::find($mediaId);

        if (!$media || !$media->isApproved()) {
            return response()->json(['error' => 'Arquivo não encontrado.'], 404);
        }

        if (!Storage::disk('private')->exists($media->path)) {
            return response()->json(['error' => 'Arquivo não encontrado.'], 404);
        }

        return Storage::disk('private')->response($media->path, null, [
            'Content-Type'  => $media->mime_type,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Extensão derivada do MIME verificado, nunca do nome enviado. */
    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'video/mp4'       => 'mp4',
            'video/quicktime' => 'mov',
            default           => 'bin',
        };
    }
}
