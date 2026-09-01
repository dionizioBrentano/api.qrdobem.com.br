<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
	trait ResolvesAdventureEntity
{
    private function adventureEntity(Request $request, $unique_code)
    {
        $entity = app(\App\Services\EntityAccessService::class)
            ->resolveEntity($request->tenant, $unique_code);

        if (!$entity) {
            return response()->json(
                ['error' => 'Registro não encontrado ou acesso negado.'],
                404
            );
        }

        if ($entity->type !== 'person') {
            return response()->json(
                ['error' => 'Trilha Aventura suportada apenas para pessoas.'],
                400
            );
        }

        return $entity;
    }
}