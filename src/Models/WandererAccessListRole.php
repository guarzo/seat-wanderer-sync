<?php

namespace Guarzo\Seat\WandererSync\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Services\Models\ExtensibleModel;
use Seat\Web\Models\Acl\Role;

/**
 * @property int $id
 * @property int $role_id
 * @property int $wanderer_instance_id
 * @property-read Role $role
 * @property-read WandererAccessListInstance $accessList
 */
class WandererAccessListRole extends ExtensibleModel
{
    protected $table = 'guarzo_wanderer_sync_role_mappings';

    protected $fillable = ['role_id', 'wanderer_instance_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function accessList(): BelongsTo
    {
        return $this->belongsTo(WandererAccessListInstance::class, 'wanderer_instance_id', 'id');
    }
}
