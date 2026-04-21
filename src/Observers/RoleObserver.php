<?php

namespace Guarzo\Seat\WandererSync\Observers;

use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Seat\Web\Models\Acl\Role;

final class RoleObserver
{
    public function deleting(Role $role): void
    {
        WandererAccessListRole::where('role_id', $role->id)->delete();
    }
}
