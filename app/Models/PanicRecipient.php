<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PanicRecipient — o resultado do alerta para UM destinatário.
 * T1-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * Existe uma linha por pessoa e por canal. É o que permite ao painel
 * responder a pergunta que importa numa emergência: quem foi avisado de
 * fato, e quem não foi.
 */
class PanicRecipient extends Model
{
    protected $fillable = [
        'panic_event_id',
        'tenant_id',
        'channel',
        'destination',
        'status',
        'provider_id',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(PanicEvent::class, 'panic_event_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
