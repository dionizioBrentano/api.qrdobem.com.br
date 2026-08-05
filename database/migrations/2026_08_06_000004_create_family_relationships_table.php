<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1, entrega 1.1 — Grafo de parentesco.
 * Referência: PLANO_TRILHAS_2026-08.md, requisito T1-R02.
 *
 * POR QUE GRAFO E NÃO ÁRVORE
 * O requisito fala em "árvore genealógica completa, com hierarquia
 * horizontal e vertical (pais, filhos primogênitos, noras, netos)". Uma
 * árvore estrita não comporta isso: cônjuges são ligação horizontal sem
 * ancestral comum, noras e genros entram por casamento, segundos
 * casamentos criam meio-irmãos, e guarda compartilhada dá dois núcleos ao
 * mesmo filho. Tudo isso é aresta tipada entre duas entidades — grafo.
 *
 * DIREÇÃO DA ARESTA
 * `from` é o sujeito e `to` é o objeto: (from=João, to=Maria, parent_of)
 * lê-se "João é pai/mãe de Maria". O inverso (`child_of`) NÃO é gravado
 * duas vezes — é derivado na consulta. Gravar os dois lados dobraria as
 * linhas e criaria a chance de ficarem inconsistentes.
 *
 * Exceção: relações simétricas (cônjuge, irmão) marcam `is_symmetric`,
 * e aí a leitura vale nos dois sentidos com uma linha só.
 *
 * SEM PERFIS ILIMITADOS NÃO HÁ ÁRVORE (T1-R01)
 * Não há limite de entidades por espaço familiar. A cobrança segue por
 * crédito de QR Code, não por perfil cadastrado — pessoa da família pode
 * existir na árvore sem ter QR próprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_relationships', function (Blueprint $table) {
            $table->id();

            // A árvore vive dentro de um espaço familiar.
            $table->foreignId('space_id')
                  ->constrained('spaces')
                  ->cascadeOnDelete();

            $table->foreignId('from_entity_id')
                  ->constrained('entities')
                  ->cascadeOnDelete();

            $table->foreignId('to_entity_id')
                  ->constrained('entities')
                  ->cascadeOnDelete();

            // Vocabulário validado na aplicação (FamilyRelationship::TYPES),
            // não no banco: parentesco novo não deve exigir migration.
            $table->string('relation_type', 40);

            // Verdadeiro para cônjuge e irmão: uma linha vale nos dois
            // sentidos. Falso para pai/filho, que tem inverso próprio.
            $table->boolean('is_symmetric')->default(false);

            // Ex.: "por afinidade", "guarda compartilhada", "primogênito".
            $table->string('note')->nullable();

            $table->foreignId('created_by_tenant_id')
                  ->nullable()
                  ->constrained('tenants')
                  ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Impede a mesma relação duas vezes entre as mesmas pessoas.
            $table->unique(
                ['from_entity_id', 'to_entity_id', 'relation_type'],
                'family_rel_unique'
            );

            $table->index(['space_id', 'relation_type']);
            $table->index('to_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_relationships');
    }
};
