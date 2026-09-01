<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\CreditBatch;
use App\Models\Space;
use App\Policies\SpacePolicy;

class EntityAccessService
{
    public function canAccessEntity($tenant, Entity $entity, string $permission = 'entity.view'): bool
    {
        $orgIds = $tenant->organizations()->pluck('organizations.id')->all();

        if ($entity->organization_id && in_array($entity->organization_id, $orgIds)) {
            return true;
        }

        if (!$entity->organization_id && $entity->credit_batch_id) {
            $batch = CreditBatch::find($entity->credit_batch_id);
            if ($batch && $batch->recipient_tenant_id === $tenant->id) {
                return true;
            }
        }

        if (!$entity->space_id) {
            return false;
        }

        try {
            $space = Space::find($entity->space_id);

            return $space
                ? app(SpacePolicy::class)->check($tenant, $space, $permission)
                : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolveEntity($tenant, string $uniqueCode, string $permission = 'entity.view'): ?Entity
    {
        $entity = Entity::where('unique_code', $uniqueCode)->first();

        if (!$entity || !$this->canAccessEntity($tenant, $entity, $permission)) {
            return null;
        }

        return $entity;
    }
}
