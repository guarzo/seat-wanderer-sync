<?php

namespace Guarzo\Seat\WandererSync\Jobs;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class UpdateWandererInstance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private readonly WandererAccessListInstance $instance,
    ) {}

    /** @return string[] */
    public function tags(): array
    {
        return ['seat-wanderer-sync'];
    }

    public function handle(SyncService $sync): void
    {
        $result = $sync->syncInstance($this->instance);

        if (app()->bound('log')) {
            logger()->info(sprintf(
                '[seat-wanderer-sync] instance=%d %s',
                $this->instance->id,
                $result->summary(),
            ));
        }
    }
}
