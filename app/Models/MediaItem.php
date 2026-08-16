<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MediaItem — foto ou vídeo com moderação obrigatória.
 * Fase 3 (T2-R05) e reutilizado na Fase 4 (T4-R07, prova social).
 *
 * NASCE 'pending' E ISSO NÃO É CONFIGURÁVEL.
 * Mídia enviada por terceiro pode trazer rosto de menor, endereço legível
 * no fundo da foto, documento sobre a mesa. Publicação automática é
 * inaceitável num sistema que lida com pessoa vulnerável.
 *
 * O arquivo fica em storage PRIVADO. O acesso público sai por URL assinada
 * e temporária, nunca por caminho direto — caminho direto adivinhável
 * vazaria mídia reprovada.
 */
class MediaItem extends Model
{
    use SoftDeletes;

    public const OWNER_SPACE        = 'space';
    public const OWNER_DISBURSEMENT = 'disbursement';
    public const OWNER_ENTITY       = 'entity';

    /** Tipos aceitos. Lista fechada: o que não está aqui é recusado. */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/quicktime',
    ];

    /** 20 MB. Hospedagem compartilhada não comporta upload grande. */
    public const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'uploaded_by_tenant_id',
        'path',
        'mime_type',
        'size_bytes',
        'caption',
        'status',
        'moderated_by_tenant_id',
        'moderated_at',
        'rejection_reason',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
        'size_bytes'   => 'integer',
    ];

    /**
     * `path` nunca sai em JSON: é o caminho no storage privado, e expô-lo
     * daria o mapa do diretório a quem quisesse tentar acesso direto.
     */
    protected $hidden = [
        'path',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'uploaded_by_tenant_id');
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'moderated_by_tenant_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }
}
