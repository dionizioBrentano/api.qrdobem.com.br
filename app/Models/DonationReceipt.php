<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DonationReceipt — comprovante/registro MANUAL de doação (legado).
 *
 * Bounded context separado da doação financeira a causa (DonationCause). É o
 * registro de doação ligada a uma necessidade (need), com recibo em papel ou
 * assinatura gov.br. Ocupava a tabela física `donations` em produção, agora
 * renomeada para `donation_receipts` (ver migration
 * 2026_08_07_000003_rename_or_create_donation_receipts).
 *
 * MODEL MÍNIMO: o repositório não tem controller/CRUD de recibos — só a
 * tabela. Nada de regra de negócio foi inventada aqui; este model existe para
 * dar nome ao agregado e permitir leitura/registro quando (e se) a UI legada
 * for portada. Ver RELATÓRIO/Dívida.
 */
class DonationReceipt extends Model
{
    use SoftDeletes;

    protected $table = 'donation_receipts';

    public const RECEIPT_PAPER = 'paper';
    public const RECEIPT_GOV_BR = 'gov_br_signature';

    protected $fillable = [
        'need_id',
        'donor_unique_code',
        'donor_name',
        'donor_contact',
        'description',
        'receipt_type',
        'receipt_file_path',
        'donated_at',
        'registered_by_tenant_id',
    ];

    protected $casts = [
        'donated_at' => 'datetime',
    ];

    public function need()
    {
        return $this->belongsTo(BeneficiaryNeed::class, 'need_id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(Tenant::class, 'registered_by_tenant_id');
    }
}
