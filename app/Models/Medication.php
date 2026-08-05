<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Medication — medicamento identificado pelo código de barras.
 * Fase 6, T1-R09 do PLANO_TRILHAS_2026-08.md.
 *
 * A BASE CRESCE POR CONFIRMAÇÃO DO USUÁRIO (decisão do proprietário)
 * Escaneia → busca na internet na primeira vez → "é este o produto que
 * você comprou?" → confirma. Três confirmações independentes tornam o
 * registro confiável.
 *
 * DIVERGÊNCIA NÃO VIRA CONFIANÇA
 * Se dois usuários apontam produtos diferentes para o mesmo EAN, o
 * registro vai para `conflict` e volta a perguntar, em vez de acumular
 * voto. Sem isso, um engano em cadeia contamina uma base que sugere
 * horário de remédio.
 */
class Medication extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_TRUSTED  = 'trusted';
    public const STATUS_CONFLICT = 'conflict';

    /**
     * Confirmações necessárias para o registro virar confiável.
     * Configurável por env: o valor certo depende do volume de uso, e
     * travar em 3 no código exigiria deploy para ajustar.
     */
    public static function trustThreshold(): int
    {
        return (int) env('MEDICATION_TRUST_THRESHOLD', 3);
    }

    protected $fillable = [
        'ean',
        'name',
        'presentation',
        'laboratory',
        'active_ingredient',
        'registry_number',
        'status',
        'confirmations_count',
        'source',
    ];

    protected $casts = [
        'confirmations_count' => 'integer',
    ];

    public function confirmations(): HasMany
    {
        return $this->hasMany(MedicationConfirmation::class);
    }

    public function leaflet(): HasOne
    {
        return $this->hasOne(MedicationLeaflet::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function isTrusted(): bool
    {
        return $this->status === self::STATUS_TRUSTED;
    }

    /** Ainda precisa perguntar ao usuário se o produto está certo? */
    public function needsConfirmation(): bool
    {
        return $this->status !== self::STATUS_TRUSTED;
    }

    /**
     * Recalcula o status a partir das confirmações.
     *
     * Chamado depois de cada voto. A contagem é feita das linhas, e não
     * incrementada às cegas, para que uma correção que apague um voto
     * antigo não deixe o contador mentindo.
     */
    public function refreshTrust(): void
    {
        $confirmed = $this->confirmations()->where('action', 'confirmed')->count();
        $corrected = $this->confirmations()->where('action', 'corrected')->count();

        // Qualquer correção instala a dúvida: alguém com a caixa na mão
        // disse que o dado está errado.
        if ($corrected > 0) {
            $status = self::STATUS_CONFLICT;
        } elseif ($confirmed >= self::trustThreshold()) {
            $status = self::STATUS_TRUSTED;
        } else {
            $status = self::STATUS_PENDING;
        }

        $this->update([
            'confirmations_count' => $confirmed,
            'status'              => $status,
        ]);
    }
}
