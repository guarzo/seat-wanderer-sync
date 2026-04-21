<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Driver;

use Guarzo\Seat\WandererSync\Driver\WandererClient;
use Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException;
use Guarzo\Seat\WandererSync\Exceptions\NotFoundException;
use Guarzo\Seat\WandererSync\Exceptions\WandererApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WandererClientTest extends TestCase
{
    private function clientWithResponses(array $responses): array
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://wanderer.ltd']);

        // No retry middleware for basic tests — we only want to verify each call once.
        $client = new WandererClient(
            'https://wanderer.ltd',
            '095be4ad-6ab4-4024-89b1-7846e25132c6',
            'token',
            ['timeout' => 5],
            $guzzle,
        );

        return [$client, $mock];
    }

    public function test_rejects_ssrf_url_on_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WandererClient('http://localhost', 'id', 'token');
    }

    public function test_fetch_members_returns_character_ids(): void
    {
        [$client] = $this->clientWithResponses([
            new Response(200, [], json_encode([
                'data' => [
                    'members' => [
                        ['eve_character_id' => '123'],
                        ['eve_character_id' => '456'],
                        ['corporation_id' => '999'], // no eve_character_id, skipped
                    ],
                ],
            ])),
        ]);

        $members = $client->fetchMembers();

        $this->assertEquals([123, 456], $members->values()->all());
    }

    public function test_fetch_acl_name_returns_name(): void
    {
        [$client] = $this->clientWithResponses([
            new Response(200, [], json_encode([
                'data' => ['name' => 'Corp Fleet ACL', 'members' => []],
            ])),
        ]);

        $this->assertSame('Corp Fleet ACL', $client->fetchAclName());
    }

    public function test_fetch_acl_name_returns_null_when_absent(): void
    {
        [$client] = $this->clientWithResponses([
            new Response(200, [], json_encode(['data' => ['members' => []]])),
        ]);

        $this->assertNull($client->fetchAclName());
    }

    public function test_add_member_succeeds(): void
    {
        [$client, $mock] = $this->clientWithResponses([new Response(201, [], '{}')]);
        $client->addMember(123);
        $this->assertSame(0, $mock->count(), 'all queued responses should be consumed');
    }

    public function test_remove_member_succeeds(): void
    {
        [$client] = $this->clientWithResponses([new Response(204)]);
        $client->removeMember(123);
        $this->expectNotToPerformAssertions();
    }

    public function test_401_raises_bad_api_key_exception(): void
    {
        [$client] = $this->clientWithResponses([new Response(401)]);

        $this->expectException(BadApiKeyException::class);
        $client->addMember(123);
    }

    public function test_404_on_remove_raises_not_found_exception(): void
    {
        [$client] = $this->clientWithResponses([new Response(404)]);

        $this->expectException(NotFoundException::class);
        $client->removeMember(123);
    }

    public function test_500_raises_generic_api_exception(): void
    {
        [$client] = $this->clientWithResponses([new Response(500)]);

        $this->expectException(WandererApiException::class);
        $client->addMember(123);
    }

    public function test_retries_on_503_then_succeeds(): void
    {
        $mock = new MockHandler([
            new Response(503),
            new Response(503),
            new Response(201, [], '{}'),
        ]);
        $stack = HandlerStack::create($mock);
        // Attach the same retry middleware the production path uses.
        $stack->push(WandererClient::retryMiddleware(3, 0.0, [500, 502, 503, 504, 429]));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://wanderer.ltd']);

        $client = new WandererClient(
            'https://wanderer.ltd',
            'id',
            'token',
            ['timeout' => 5],
            $guzzle,
        );

        $client->addMember(123);

        $this->assertSame(0, $mock->count(), 'retry should have consumed all 3 responses');
    }
}
