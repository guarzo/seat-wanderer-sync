<?php

namespace Guarzo\Seat\WandererSync\Seeders;

use Seat\Services\Seeding\AbstractScheduleSeeder;

final class ScheduleSeeder extends AbstractScheduleSeeder
{
    public function getSchedules(): array
    {
        return [[
            'command' => 'wanderer-sync:run',
            'expression' => sprintf('%d * * * *', random_int(0, 59)),
            'allow_overlap' => false,
            'allow_maintenance' => false,
            'ping_before' => null,
            'ping_after' => null,
        ]];
    }

    public function getDeprecatedSchedules(): array
    {
        // Remove the upstream command name so users who switch forks don't run both.
        return ['wanderer:sync'];
    }
}
