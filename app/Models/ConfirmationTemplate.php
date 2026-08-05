<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ConfirmationTemplate — o molde de um caso de uso B2B.
 * Fase 5, T3-R05, T3-R06 e T3-R07 do PLANO_TRILHAS_2026-08.md.
 *
 * DECISÃO DE ARQUITETURA
 * Certificação de EPI, liberação de material para terceirizado e portaria
 * de condomínio são o mesmo primitivo: evento de confirmação autenticada
 * vinculado a um QR. Três módulos separados triplicariam a manutenção e
 * ainda deixariam de fora o quarto caso que aparecer.
 *
 * Aqui o caso de uso é CONFIGURAÇÃO: quais campos preencher, se exige
 * senha, se exige foto. Um caso novo é uma linha, não uma sprint.
 */
class ConfirmationTemplate extends Model
{
    public const USE_CASE_EPI       = 'epi';
    public const USE_CASE_LOGISTICS = 'logistics';
    public const USE_CASE_CONCIERGE = 'concierge';
    public const USE_CASE_CUSTOM    = 'custom';

    public const USE_CASES = [
        self::USE_CASE_EPI,
        self::USE_CASE_LOGISTICS,
        self::USE_CASE_CONCIERGE,
        self::USE_CASE_CUSTOM,
    ];

    /**
     * Moldes prontos para os três casos do requisito. Servem de ponto de
     * partida na criação — o parceiro ajusta depois.
     */
    public const PRESETS = [
        self::USE_CASE_EPI => [
            'name'   => 'Entrega de EPI',
            'fields' => [
                ['key' => 'equipment',  'label' => 'Equipamento',      'type' => 'text',   'required' => true],
                ['key' => 'ca_number',  'label' => 'Número do CA',     'type' => 'text',   'required' => false],
                ['key' => 'quantity',   'label' => 'Quantidade',       'type' => 'number', 'required' => true],
                ['key' => 'condition',  'label' => 'Estado do item',   'type' => 'text',   'required' => false],
            ],
            // O requisito é explícito: leitura do QR + senha do funcionário.
            'requires_password' => true,
            'requires_photo'    => false,
        ],
        self::USE_CASE_LOGISTICS => [
            'name'   => 'Liberação de material',
            'fields' => [
                ['key' => 'material',    'label' => 'Material',        'type' => 'text',   'required' => true],
                ['key' => 'quantity',    'label' => 'Quantidade',      'type' => 'number', 'required' => true],
                ['key' => 'destination', 'label' => 'Destino',         'type' => 'text',   'required' => false],
                ['key' => 'company',     'label' => 'Empresa terceira','type' => 'text',   'required' => true],
            ],
            'requires_password' => true,
            'requires_photo'    => false,
        ],
        self::USE_CASE_CONCIERGE => [
            'name'   => 'Entrega de encomenda',
            'fields' => [
                ['key' => 'unit',      'label' => 'Unidade / Apto', 'type' => 'text', 'required' => true],
                ['key' => 'carrier',   'label' => 'Transportadora', 'type' => 'text', 'required' => false],
                ['key' => 'volumes',   'label' => 'Volumes',        'type' => 'number', 'required' => false],
            ],
            'requires_password' => true,
            // Encomenda avariada vira discussão; a foto encerra o assunto.
            'requires_photo'    => true,
        ],
    ];

    protected $fillable = [
        'space_id',
        'name',
        'slug',
        'use_case',
        'fields',
        'requires_password',
        'requires_photo',
        'is_active',
    ];

    protected $casts = [
        'fields'            => 'array',
        'requires_password' => 'boolean',
        'requires_photo'    => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConfirmationEvent::class, 'template_id');
    }

    /**
     * Valida o payload contra os campos declarados.
     * Devolve a lista de erros — vazia quando está tudo certo.
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];

        foreach (($this->fields ?? []) as $field) {
            $key = $field['key'] ?? null;

            if (!$key) {
                continue;
            }

            $value = $payload[$key] ?? null;
            $isEmpty = $value === null || $value === '';

            if (!empty($field['required']) && $isEmpty) {
                $errors[$key] = ($field['label'] ?? $key) . ' é obrigatório.';
                continue;
            }

            if (!$isEmpty && ($field['type'] ?? 'text') === 'number' && !is_numeric($value)) {
                $errors[$key] = ($field['label'] ?? $key) . ' precisa ser um número.';
            }
        }

        return $errors;
    }
}
