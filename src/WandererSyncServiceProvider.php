<?php

namespace Guarzo\Seat\WandererSync;

use Guarzo\Seat\WandererSync\Jobs\UpdateWandererInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Observers\RoleObserver;
use Guarzo\Seat\WandererSync\Seeders\ScheduleSeeder;
use Guarzo\Seat\WandererSync\Services\EloquentUserCharacterResolver;
use Guarzo\Seat\WandererSync\Services\UserCharacterResolver;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;
use Seat\Services\AbstractSeatPlugin;
use Seat\Web\Models\Acl\Role;

final class WandererSyncServiceProvider extends AbstractSeatPlugin
{
    public function boot(): void
    {
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/Http/routes.php';
        }

        $this->loadViewsFrom(__DIR__ . '/resources/views/', 'wanderer-sync');
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang/', 'wanderer-sync');
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations/');

        $this->registerDatabaseSeeders([ScheduleSeeder::class]);

        Role::observe(RoleObserver::class);

        Artisan::command('wanderer-sync:run', function () {
            foreach (WandererAccessListInstance::all() as $instance) {
                UpdateWandererInstance::dispatch($instance);
            }
        })->purpose('Dispatch Wanderer ACL sync jobs for every configured instance.');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/config.php', 'wanderer-sync');
        $this->registerPermissions(__DIR__ . '/Config/permissions.php', 'wanderer-sync');
        $this->mergeConfigFrom(__DIR__ . '/Config/sidebar.php', 'package.sidebar');

        $this->app->bind(UserCharacterResolver::class, EloquentUserCharacterResolver::class);

        // Provide a PSR LoggerInterface for SyncService.
        $this->app->bind(LoggerInterface::class, fn ($app) => $app->make('log'));
    }

    public function getName(): string
    {
        return 'SeAT Wanderer Sync';
    }

    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/guarzo/seat-wanderer-sync';
    }

    public function getPackagistPackageName(): string
    {
        return 'seat-wanderer-sync';
    }

    public function getPackagistVendorName(): string
    {
        return 'guarzo';
    }
}
