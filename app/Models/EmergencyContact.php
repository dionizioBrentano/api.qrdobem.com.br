<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_tenant_id',
        'space_id',
        'entity_id',
        'name',
        'phone',
        'email',
        'invite_token',
        'status',
        'term_version',
        'term_accepted_at',
        'push_subscription',
        'linked_tenant_id',
    ];

    protected $casts = [
        'term_accepted_at' => 'datetime',
        'push_subscription' => 'array',
    ];

    /**
     * O tenant que criou/convidou este contato
     */
    public function ownerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'owner_tenant_id');
    }

    /**
     * O espao a que este contato pertence (se houver)
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * A entidade especfica a que este contato pertence (se houver)
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Tenant vinculado caso a pessoa crie uma conta completa no futuro
     */
    public function linkedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'linked_tenant_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
