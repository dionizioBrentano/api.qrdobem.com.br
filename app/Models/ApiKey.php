<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ApiKey — credencial de integração do parceiro corporativo.
 * Fase 5, T3-R01 do PLANO_TRILHAS_2026-08.md.
 *
 * PAR key_id + secret, e só o HASH do segredo é guardado.
 * O `key_id` é público e serve para localizar o registro sem varrer a
 * tabela testando hash contra hash — o que seria O(n) chamadas de bcrypt
 * por requisição. O `secret` aparece uma única vez, na criação.
 *
 * Escopos são verificados por prefixo: `entities.read` casa com
 * `entities.*`. Sem isso, cada permissão nova exigiria reemitir a chave de
 * todos os parceiros.
 */
class ApiKey extends Model
{
    public const SCOPES = [
        'entities.read',
        'entities.write',
        'confirmations.read',
        'confirmations.write',
        'reports.read',
    ];

    protected $fillable = [
        'space_id',
        'name',
        'key_id',
        'secret_hash',
        'scopes',
        'rate_limit_per_minute',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes'       => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /** O hash nunca sai em resposta. */
    protected $hidden = [
        'secret_hash',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Cria a chave e devolve [modelo, segredo em claro].
     * O segredo em claro só existe nesta chamada — depois disso, nem nós
     * conseguimos recuperá-lo.
     */
    public static function issue(Space $space, string $name, array $scopes, int $rateLimit = 60): array
    {
        $keyId  = 'qdb_' . Str::lower(Str::random(24));
        $secret = Str::random(48);

        $key = static::create([
            'space_id'              => $space->id,
            'name'                  => $name,
            'key_id'                => $keyId,
            'secret_hash'           => Hash::make($secret),
            'scopes'                => $scopes,
            'rate_limit_per_minute' => $rateLimit,
        ]);

        return [$key, $secret];
    }

    public function verifySecret(string $secret): bool
    {
        return Hash::check($secret, $this->secret_hash);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /** Escopo concedido? Aceita curinga por prefixo (`entities.*`). */
    public function hasScope(string $scope): bool
    {
        $granted = $this->scopes ?? [];

        if (in_array($scope, $granted, true)) {
            return true;
        }

        $prefix = explode('.', $scope)[0] . '.*';

        return in_array($prefix, $granted, true);
    }
}
