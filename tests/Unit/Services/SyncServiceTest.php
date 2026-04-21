<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Services;

use Guarzo\Seat\WandererSync\Driver\WandererClient;
use Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException;
use Guarzo\Seat\WandererSync\Exceptions\NotFoundException;
use Guarzo\Seat\WandererSync\Exceptions\WandererApiException;
use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Services\SyncService;
use Guarzo\Seat\WandererSync\Services\UserCharacterResolver;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build an instance stub whose `client()` method returns the provided mock WandererClient.
     * Uses a Mockery partial mock so we can override client() but keep a real ->id attribute.
     */
    private function instanceWithClient(WandererClient $client, int $mappingCount = 1): WandererAccessListInstance
    {
        $instance = Mockery::mock(WandererAccessListInstance::class)->makePartial();
        $instance->shouldReceive('client')->andReturn($client);
        $instance->shouldReceive('getAttribute')->with('id')->andReturn(1);
        // SyncService checks mapping count via the resolver; we don't consult the DB.
        return $instance;
    }

    private function resolverReturning(array $ids): UserCharacterResolver
    {
        $r = Mockery::mock(UserCharacterResolver::class);
        $r->shouldReceive('allowedCharacterIdsForInstance')->andReturn(collect($ids));
        return $r;
    }

    public function test_empty_allowed_and_empty_current_is_noop(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([]));
        $client->shouldNotReceive('addMember');
        $client->shouldNotReceive('removeMember');

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(0, $r->added);
        $this->assertSame(0, $r->removed);
        $this->assertSame([], $r->failed);
    }

    public function test_adds_missing_characters(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([]));
        $client->shouldReceive('addMember')->with(111)->once();
        $client->shouldReceive('addMember')->with(222)->once();

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([111, 222]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(2, $r->added);
        $this->assertSame(0, $r->removed);
    }

    public function test_removes_unauthorized_characters(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([111, 999]));
        $client->shouldReceive('removeMember')->with(999)->once();

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([111]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(0, $r->added);
        $this->assertSame(1, $r->removed);
    }

    public function test_mixed_add_and_remove(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([111, 999]));
        $client->shouldReceive('removeMember')->with(999)->once();
        $client->shouldReceive('addMember')->with(222)->once();

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([111, 222]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(1, $r->added);
        $this->assertSame(1, $r->removed);
    }

    public function test_not_found_on_remove_counts_as_success(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([999]));
        $client->shouldReceive('removeMember')->with(999)
            ->andThrow(new NotFoundException('gone', 404));

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(1, $r->removed);
        $this->assertSame([], $r->failed);
    }

    public function test_generic_api_exception_on_single_character_continues_others(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([]));
        $client->shouldReceive('addMember')->with(111)->once()
            ->andThrow(new WandererApiException('500', 500));
        $client->shouldReceive('addMember')->with(222)->once();

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([111, 222]), new NullLogger());

        $r = $svc->syncInstance($instance);

        $this->assertSame(1, $r->added);
        $this->assertSame([111], $r->failed);
    }

    public function test_bad_api_key_aborts_run(): void
    {
        $client = Mockery::mock(WandererClient::class);
        $client->shouldReceive('fetchMembers')->andReturn(collect([]));
        $client->shouldReceive('addMember')->with(111)->once()
            ->andThrow(new BadApiKeyException('401', 401));
        // 222 must never be attempted.
        $client->shouldNotReceive('addMember')->with(222);

        $instance = $this->instanceWithClient($client);
        $svc = new SyncService($this->resolverReturning([111, 222]), new NullLogger());

        $this->expectException(BadApiKeyException::class);
        $svc->syncInstance($instance);
    }
}
