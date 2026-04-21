<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Guarzo\Seat\WandererSync\Services\MappingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

final class MappingServiceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Seat\Services\ServicesServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // Create the minimal 'roles' table the plugin's FK references.
        // `increments` matches SeAT's actual roles.id (unsigned int), which is why
        // our role_mappings.role_id FK is `integer()->unsigned()`.
        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('title');
        });

        $this->loadMigrationsFrom(__DIR__ . '/../../../src/database/migrations');
    }

    private function svc(): MappingService
    {
        return new MappingService();
    }

    private function seedRole(int $id = 1): void
    {
        DB::table('roles')->insert(['id' => $id, 'title' => "role-$id"]);
    }

    public function test_create_instance_success_with_valid_url(): void
    {
        $o = $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret');
        $this->assertTrue($o->isSuccess());
        $this->assertDatabaseCount('guarzo_wanderer_sync_instances', 1);
    }

    public function test_create_instance_invalid_url_returns_error(): void
    {
        $o = $this->svc()->createInstance('http://localhost', 'abc', 'secret');
        $this->assertTrue($o->isError());
        $this->assertSame('url_invalid', $o->reasonKey());
        $this->assertDatabaseCount('guarzo_wanderer_sync_instances', 0);
    }

    public function test_duplicate_instance_returns_existed(): void
    {
        $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret1');
        $o = $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret2');

        $this->assertTrue($o->isExisted());
        $this->assertDatabaseCount('guarzo_wanderer_sync_instances', 1);
    }

    public function test_create_mapping_success(): void
    {
        $this->seedRole(1);
        $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret');
        $instance = WandererAccessListInstance::first();

        $o = $this->svc()->createMapping(1, $instance->id);

        $this->assertTrue($o->isSuccess());
        $this->assertDatabaseCount('guarzo_wanderer_sync_role_mappings', 1);
    }

    public function test_duplicate_mapping_returns_existed(): void
    {
        $this->seedRole(1);
        $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret');
        $instance = WandererAccessListInstance::first();

        $this->svc()->createMapping(1, $instance->id);
        $o = $this->svc()->createMapping(1, $instance->id);

        $this->assertTrue($o->isExisted());
        $this->assertDatabaseCount('guarzo_wanderer_sync_role_mappings', 1);
    }

    public function test_delete_mapping(): void
    {
        $this->seedRole(1);
        $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret');
        $instance = WandererAccessListInstance::first();
        $this->svc()->createMapping(1, $instance->id);
        $mapping = WandererAccessListRole::first();

        $this->svc()->deleteMapping($mapping->id);

        $this->assertDatabaseCount('guarzo_wanderer_sync_role_mappings', 0);
    }

    public function test_delete_instance_cascades_mappings(): void
    {
        $this->seedRole(1);
        $this->svc()->createInstance('https://wanderer.ltd', 'abc', 'secret');
        $instance = WandererAccessListInstance::first();
        $this->svc()->createMapping(1, $instance->id);

        $this->svc()->deleteInstance($instance->id);

        $this->assertDatabaseCount('guarzo_wanderer_sync_instances', 0);
        $this->assertDatabaseCount('guarzo_wanderer_sync_role_mappings', 0);
    }
}
