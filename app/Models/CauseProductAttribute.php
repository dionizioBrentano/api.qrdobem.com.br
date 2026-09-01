<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CauseProductAttribute extends Model
{
    use HasFactory;

    public const SIGNIFICANCE_FINANCEIRO = 'financeiro';
    public const SIGNIFICANCE_IDENTIDADE = 'identidade';
    public const SIGNIFICANCE_APRESENTACAO = 'apresentacao';
    public const SIGNIFICANCE_COMERCIAL = 'comercial';
    public const SIGNIFICANCE_LOGISTICA = 'logistica';
    public const SIGNIFICANCE_USO = 'uso';

    protected $fillable = [
        'product_id',
        'attr_key',
        'attr_value',
        'significance',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(CauseProduct::class, 'product_id');
    }
}
