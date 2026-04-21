<?php

namespace Guarzo\Seat\WandererSync\Models;

use Guarzo\Seat\WandererSync\Driver\WandererClient;
use Illuminate\Support\Facades\Cache;
use Seat\Services\Models\ExtensibleModel;

/**
 * @property int    $id
 * @property string $wanderer_url
 * @property string $access_list_id
 * @property string $access_list_token
 */
class WandererAccessListInstance extends ExtensibleModel
{
    protected $table = 'guarzo_wanderer_sync_instances';

    protected $fillable = ['wanderer_url', 'access_list_id', 'access_list_token'];

    /**
     * Factory for the Wanderer HTTP client.
     *
     * Test seams may override this (e.g. via Mockery partial mock) to return a stub client.
     */
    public function client(): WandererClient
    {
        return new WandererClient(
            $this->wanderer_url,
            $this->access_list_id,
            $this->access_list_token,
            config('wanderer-sync') ?? [],
        );
    }

    /**
     * Resolve the ACL's human-readable name, cached.
     * Returns null on cache miss and API error (view falls back to UUID).
     */
    public function aclName(): ?string
    {
        $ttl = (int) config('wanderer-sync.acl_name_cache_ttl', 600);
        $key = "guarzo.wanderer_sync.acl_name.{$this->id}";

        return Cache::remember($key, $ttl, function (): ?string {
            try {
                return $this->client()->fetchAclName();
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
