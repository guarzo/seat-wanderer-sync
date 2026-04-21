<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentUserCharacterResolver implements UserCharacterResolver
{
    public function allowedCharacterIdsForInstance(WandererAccessListInstance $instance): Collection
    {
        // SELECT DISTINCT rt.character_id
        // FROM refresh_tokens rt
        // JOIN role_user ru ON ru.user_id = rt.user_id
        // JOIN guarzo_wanderer_sync_role_mappings m ON m.role_id = ru.role_id
        // WHERE m.wanderer_instance_id = ?
        return DB::table('refresh_tokens')
            ->join('role_user', 'role_user.user_id', '=', 'refresh_tokens.user_id')
            ->join('guarzo_wanderer_sync_role_mappings', 'guarzo_wanderer_sync_role_mappings.role_id', '=', 'role_user.role_id')
            ->where('guarzo_wanderer_sync_role_mappings.wanderer_instance_id', $instance->id)
            ->distinct()
            ->pluck('refresh_tokens.character_id')
            ->map(fn ($id) => (int) $id);
    }
}
