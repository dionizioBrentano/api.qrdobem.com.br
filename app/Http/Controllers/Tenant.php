<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Representa uma empresa locatária (Tenant) que gerencia as entidades.
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'document_number',
        'firebase_uid',
        'is_active',
    ];

    /**
     * Uma empresa pode gerenciar várias entidades.
     */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }
}
