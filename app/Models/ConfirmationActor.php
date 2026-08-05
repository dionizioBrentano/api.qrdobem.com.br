<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * ConfirmationActor — quem confirma: funcionário, terceirizado, morador.
 * Fase 5, T3-R05 a T3-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * `external_id` é a matrícula, o número do apartamento ou o que o parceiro
 * já usa internamente. Existe para que a integração por API não precise
 * conhecer o nosso ID — o sistema do cliente manda a matrícula dele.
 *
 * A senha é o segundo fator do requisito de EPI ("leitura do QR + senha do
 * funcionário"). Guardada como hash, como qualquer senha.
 */
class ConfirmationActor extends Model
{
    protected $fillable = [
        'space_id',
        'name',
        'external_id',
        'role',
        'password_hash',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function setPassword(string $password): void
    {
        $this->update(['password_hash' => Hash::make($password)]);
    }

    public function checkPassword(string $password): bool
    {
        if (!$this->password_hash) {
            return false;
        }

        return Hash::check($password, $this->password_hash);
    }
}
