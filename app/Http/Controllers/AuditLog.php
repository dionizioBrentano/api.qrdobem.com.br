<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registra a auditoria de acesso aos dados criptografados das Entidades.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'ip_address',
        'user_agent',
        'accessed_at',
        'location_data',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
        'location_data' => 'array',
    ];

    /**
     * O log pertence a uma entidade visualizada.
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
