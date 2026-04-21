<?php

namespace Guarzo\Seat\WandererSync\Observers;

use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Seat\Web\Models\Acl\Role;

/**
 * Belt-and-suspenders with the `role_id` FK's ON DELETE CASCADE.
 *
 * Using the `deleted` hook (after successful delete) rather than `deleting`,
 * so a rolled-back Role::delete() does not leave orphaned mapping-table side effects.
 */
final class RoleObserver
{
    public function deleted(Role $role): void
    {
        WandererAccessListRole::where('role_id', $role->id)->delete();
    }
}
