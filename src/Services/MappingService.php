<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Guarzo\Seat\WandererSync\Support\Outcome;
use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class MappingService
{
    public function createInstance(string $url, string $aclId, string $token): Outcome
    {
        try {
            $normalized = WandererUrlValidator::validate($url);
        } catch (InvalidArgumentException) {
            return Outcome::error('url_invalid');
        }

        $existing = WandererAccessListInstance::query()
            ->where('wanderer_url', $normalized)
            ->where('access_list_id', $aclId)
            ->first();
        if ($existing) {
            return Outcome::existed();
        }

        try {
            WandererAccessListInstance::create([
                'wanderer_url' => $normalized,
                'access_list_id' => $aclId,
                'access_list_token' => $token,
            ]);
        } catch (QueryException $e) {
            // Defensive: unique index race; treat as existed.
            return Outcome::existed();
        }

        return Outcome::success();
    }

    public function deleteInstance(int $instanceId): void
    {
        WandererAccessListInstance::destroy($instanceId);
    }

    public function createMapping(int $roleId, int $instanceId): Outcome
    {
        $existing = WandererAccessListRole::query()
            ->where('role_id', $roleId)
            ->where('wanderer_instance_id', $instanceId)
            ->first();
        if ($existing) {
            return Outcome::existed();
        }

        try {
            WandererAccessListRole::create([
                'role_id' => $roleId,
                'wanderer_instance_id' => $instanceId,
            ]);
        } catch (QueryException) {
            return Outcome::existed();
        }

        return Outcome::success();
    }

    public function deleteMapping(int $mappingId): void
    {
        WandererAccessListRole::destroy($mappingId);
    }
}
