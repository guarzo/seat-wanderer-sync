<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Illuminate\Support\Collection;

interface UserCharacterResolver
{
    /**
     * Return the de-duplicated set of character IDs that should be on the given instance's ACL,
     * based on the currently-configured SeAT role mappings.
     *
     * @return Collection<int, int>
     */
    public function allowedCharacterIdsForInstance(WandererAccessListInstance $instance): Collection;
}
