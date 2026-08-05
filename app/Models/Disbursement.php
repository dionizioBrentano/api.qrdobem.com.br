<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disbursement — o repasse ao beneficiário. Fase 4, T4-R03, T4-R06, T4-R08.
 *
 * MÁQUINA DE ESTADOS
 *   requested → approved → sent → confirmed
 *                            └──→ disputed
 *
 * `confirmed` é o único estado que conta como repasse concluído, e só se
 * chega nele com a contraprova do beneficiário (T4-R06): leitura do QR de
 * validação mais um fator aceito. Sem isso o dinheiro sai sem prova de que
 * chegou — que é exatamente a fraude que a trilha existe para impedir.
 *
 * `disputed` existe porque a alternativa seria pior: sem ele, um repasse
 * contestado ficaria eternamente em `sent`, indistinguível de um que
 * simplesmente ainda não foi confirmado.
 *
 * RESSARCIMENTO (T4-R08)
 * Não há recompensa financeira por resgate. A família pode autorizar o
 * ressarcimento de custo operacional ao benfeitor, com TETO — por isso
 * `reimbursement_cap` existe separado de `reimbursement_amount`: o valor
 * pago é conferido contra o teto no momento da autorização.
 */
class Disbursement extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISPUTED  = 'disputed';

    /** Transições permitidas. Fora daqui, o pedido é recusado. */
    public const TRANSITIONS = [
        self::STATUS_REQUESTED => [self::STATUS_APPROVED, self::STATUS_DISPUTED],
        self::STATUS_APPROVED  => [self::STATUS_SENT, self::STATUS_DISPUTED],
        self::STATUS_SENT      => [self::STATUS_CONFIRMED, self::STATUS_DISPUTED],
        self::STATUS_CONFIRMED => [self::STATUS_DISPUTED],
        self::STATUS_DISPUTED  => [self::STATUS_APPROVED],
    ];

    protected $fillable = [
        'beneficiary_id',
        'need_id',
        'cause_space_id',
        'kind',
        'description',
        'amount',
        'status',
        'approved_by_tenant_id',
        'approved_at',
        'sent_at',
        'proof_factor',
        'confirmed_by_tenant_id',
        'confirmed_at',
        'confirmation_ip',
        'benefactor_tenant_id',
        'reimbursement_amount',
        'reimbursement_cap',
        'reimbursement_authorized',
    ];

    protected $casts = [
        'amount'                   => 'decimal:2',
        'reimbursement_amount'     => 'decimal:2',
        'reimbursement_cap'        => 'decimal:2',
        'reimbursement_authorized' => 'boolean',
        'approved_at'              => 'datetime',
        'sent_at'                  => 'datetime',
        'confirmed_at'             => 'datetime',
    ];

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryNeed::class, 'need_id');
    }

    public function cause(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'cause_space_id');
    }

    public function benefactor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'benefactor_tenant_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * O ressarcimento respeita o teto?
     * Verificado aqui e não só no controller para que qualquer caminho que
     * grave o valor passe pela mesma regra.
     */
    public function reimbursementWithinCap(): bool
    {
        if ($this->reimbursement_amount === null) {
            return true;
        }

        if ($this->reimbursement_cap === null) {
            return false; // sem teto definido, não se autoriza pagamento
        }

        return (float) $this->reimbursement_amount <= (float) $this->reimbursement_cap;
    }
}
