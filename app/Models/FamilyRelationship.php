<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FamilyRelationship — aresta tipada do grafo de parentesco.
 * Fase 1, entrega 1.1 do PLANO_TRILHAS_2026-08.md (T1-R02).
 *
 * Leitura da aresta: (from) É (relation_type) DE (to).
 *   (from=João, to=Maria, parent_of)  → João é pai/mãe de Maria
 *   (from=João, to=Ana,   spouse_of)  → João é cônjuge de Ana (simétrico)
 *
 * Relações com inverso (`INVERSES`) são gravadas UMA vez e lidas nos dois
 * sentidos pelo serviço de árvore. Simétricas dispensam inverso.
 *
 * `parent_of` cobre pai e mãe de propósito: o gênero está na entidade, não
 * no parentesco — e assim adoção, duas mães e dois pais entram sem caso
 * especial.
 */
class FamilyRelationship extends Model
{
    use SoftDeletes;

    // Verticais (com inverso)
    public const PARENT_OF      = 'parent_of';
    public const CHILD_OF       = 'child_of';
    public const GRANDPARENT_OF = 'grandparent_of';
    public const GRANDCHILD_OF  = 'grandchild_of';
    public const GUARDIAN_OF    = 'guardian_of';   // tutela, curatela
    public const WARD_OF        = 'ward_of';

    // Horizontais (simétricas)
    public const SPOUSE_OF  = 'spouse_of';
    public const SIBLING_OF = 'sibling_of';

    // Por afinidade (com inverso)
    public const PARENT_IN_LAW_OF = 'parent_in_law_of'; // sogro/sogra
    public const CHILD_IN_LAW_OF  = 'child_in_law_of';  // nora/genro
    public const SIBLING_IN_LAW_OF = 'sibling_in_law_of'; // cunhado — simétrico

    // Pet: quem é o tutor responsável pelo animal na família
    public const CARETAKER_OF = 'caretaker_of';
    public const PET_OF       = 'pet_of';

    public const TYPES = [
        self::PARENT_OF, self::CHILD_OF,
        self::GRANDPARENT_OF, self::GRANDCHILD_OF,
        self::GUARDIAN_OF, self::WARD_OF,
        self::SPOUSE_OF, self::SIBLING_OF,
        self::PARENT_IN_LAW_OF, self::CHILD_IN_LAW_OF, self::SIBLING_IN_LAW_OF,
        self::CARETAKER_OF, self::PET_OF,
    ];

    /** Relações que valem nos dois sentidos com uma linha só. */
    public const SYMMETRIC = [
        self::SPOUSE_OF,
        self::SIBLING_OF,
        self::SIBLING_IN_LAW_OF,
    ];

    /** Inverso de cada relação direcional. */
    public const INVERSES = [
        self::PARENT_OF        => self::CHILD_OF,
        self::CHILD_OF         => self::PARENT_OF,
        self::GRANDPARENT_OF   => self::GRANDCHILD_OF,
        self::GRANDCHILD_OF    => self::GRANDPARENT_OF,
        self::GUARDIAN_OF      => self::WARD_OF,
        self::WARD_OF          => self::GUARDIAN_OF,
        self::PARENT_IN_LAW_OF => self::CHILD_IN_LAW_OF,
        self::CHILD_IN_LAW_OF  => self::PARENT_IN_LAW_OF,
        self::CARETAKER_OF     => self::PET_OF,
        self::PET_OF           => self::CARETAKER_OF,
    ];

    /** Rótulos em português, para o frontend não repetir esse dicionário. */
    public const LABELS = [
        self::PARENT_OF        => 'pai/mãe de',
        self::CHILD_OF         => 'filho(a) de',
        self::GRANDPARENT_OF   => 'avô/avó de',
        self::GRANDCHILD_OF    => 'neto(a) de',
        self::GUARDIAN_OF      => 'responsável legal por',
        self::WARD_OF          => 'sob responsabilidade de',
        self::SPOUSE_OF        => 'cônjuge de',
        self::SIBLING_OF       => 'irmão/irmã de',
        self::PARENT_IN_LAW_OF => 'sogro(a) de',
        self::CHILD_IN_LAW_OF  => 'nora/genro de',
        self::SIBLING_IN_LAW_OF => 'cunhado(a) de',
        self::CARETAKER_OF     => 'tutor(a) de',
        self::PET_OF           => 'pet de',
    ];

    protected $fillable = [
        'space_id',
        'from_entity_id',
        'to_entity_id',
        'relation_type',
        'is_symmetric',
        'note',
        'created_by_tenant_id',
    ];

    protected $casts = [
        'is_symmetric' => 'boolean',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function fromEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'from_entity_id');
    }

    public function toEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'to_entity_id');
    }

    public static function isSymmetric(string $type): bool
    {
        return in_array($type, self::SYMMETRIC, true);
    }

    public static function inverseOf(string $type): ?string
    {
        return self::INVERSES[$type] ?? null;
    }

    public static function labelOf(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
