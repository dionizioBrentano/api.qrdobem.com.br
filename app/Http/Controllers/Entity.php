<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Representa o indivíduo vulnerável, pet ou objeto sujeito a extravio.
 */
class Entity extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'unique_code',
        'type',
        'encrypted_name',
        'encrypted_contact_phone',
        'encrypted_contact_email',
        'encrypted_medical_info',
        'encrypted_additional_info',
        'is_active',
    ];

    /**
     * IMPORTANTE: O Laravel usa a configuração 'APP_KEY' para criptografar
     * bidirecionalmente (AES-256-CBC) esses atributos automaticamente ao salvar e 
     * descriptografar ao ler do banco. Isso garante a conformidade com a LGPD 
     * e o sigilo de dados médicos/contatos.
     */
    protected $casts = [
        'encrypted_name' => 'encrypted',
        'encrypted_contact_phone' => 'encrypted',
        'encrypted_contact_email' => 'encrypted',
        'encrypted_medical_info' => 'encrypted',
        'encrypted_additional_info' => 'encrypted',
        'is_active' => 'boolean',
    ];

    /**
     * Cada entidade pertence a uma empresa (Tenant).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Histórico de acessos fáticos (logs de auditoria).
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
