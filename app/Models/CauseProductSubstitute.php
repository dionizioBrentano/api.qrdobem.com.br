<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CauseProductSubstitute extends Model
{
    use HasFactory;

    public const REASON_FALTA = 'falta';
    public const REASON_PRECO = 'preco';
    public const REASON_FINALIDADE = 'finalidade';

    protected $fillable = [
        'product_id',
        'substitute_id',
        'sort_order',
        'reason',
        'qty_equivalent',
    ];

    protected $casts = [
        'qty_equivalent' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(CauseProduct::class, 'product_id');
    }

    public function substitute(): BelongsTo
    {
        return $this->belongsTo(CauseProduct::class, 'substitute_id');
    }
}
