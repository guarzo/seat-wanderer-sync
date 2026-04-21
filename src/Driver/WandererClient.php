<?php

namespace Guarzo\Seat\WandererSync\Driver;

use Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException;
use Guarzo\Seat\WandererSync\Exceptions\NotFoundException;
use Guarzo\Seat\WandererSync\Exceptions\WandererApiException;
use Guarzo\Seat\WandererSync\Support\TokenSanitizer;
use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class WandererClient
{
    private readonly string $url;
    private readonly string $id;
    private readonly string $token;
    private readonly int $timeout;
    private readonly Client $http;

    /**
     * @param  array{timeout?: int, retry_total?: int, retry_backoff?: float, retry_status_codes?: int[]}  $config
     * @param  Client|null  $injectedClient  For tests: inject a Guzzle client whose handler the tests control.
     */
    public function __construct(
        string $url,
        string $id,
        string $token,
        array $config = [],
        ?Client $injectedClient = null,
    ) {
        $this->url = WandererUrlValidator::validate($url);
        $this->id = $id;
        $this->token = $token;
        $this->timeout = $config['timeout'] ?? 10;

        if ($injectedClient !== null) {
            // Tests own the handler stack and decide whether to include retry middleware
            // (via self::retryMiddleware()). This keeps the production path simple.
            $this->http = $injectedClient;
            return;
        }

        $stack = HandlerStack::create();
        $stack->push(self::retryMiddleware(
            $config['retry_total'] ?? 3,
            $config['retry_backoff'] ?? 0.5,
            $config['retry_status_codes'] ?? [500, 502, 503, 504, 429],
        ));

        $this->http = new Client([
            'base_uri' => $this->url,
            'handler' => $stack,
            'timeout' => $this->timeout,
        ]);
    }

    /** @return Collection<int, int> */
    public function fetchMembers(): Collection
    {
        $payload = $this->call('GET', "/api/acls/{$this->id}");

        $members = $payload['data']['members'] ?? [];
        $ids = [];
        foreach ($members as $member) {
            if (!array_key_exists('eve_character_id', $member)) {
                continue;
            }
            $ids[] = (int) $member['eve_character_id'];
        }

        return collect($ids);
    }

    public function fetchAclName(): ?string
    {
        $payload = $this->call('GET', "/api/acls/{$this->id}");
        $name = $payload['data']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function addMember(int $characterId): void
    {
        $this->call('POST', "/api/acls/{$this->id}/members", [
            'member' => [
                'eve_character_id' => (string) $characterId,
                'role' => 'member',
            ],
        ]);
    }

    public function removeMember(int $characterId): void
    {
        $this->call('DELETE', "/api/acls/{$this->id}/members/{$characterId}");
    }

    /**
     * Perform an HTTP call, translating transport and status errors into typed exceptions.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>  Decoded JSON body (empty array on 204).
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ],
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new WandererApiException(
                sprintf('Transport error calling %s %s: %s', $method, $path, $e->getMessage()),
                null,
                $e,
            );
        } catch (GuzzleException $e) {
            // All remaining Guzzle errors (BadResponseException etc.); translate by status.
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : null;
            throw $this->translateStatus($status, $method, $path, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw $this->translateStatus($status, $method, $path);
        }

        if (app()->bound('log')) {
            logger()->debug(sprintf(
                '[seat-wanderer-sync] [%d %s] %s /%s (token %s)',
                $status,
                $response->getReasonPhrase(),
                $method,
                ltrim($path, '/'),
                TokenSanitizer::maskApiKey($this->token),
            ));
        }

        if ($status === 204) {
            return [];
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function translateStatus(?int $status, string $method, string $path, ?\Throwable $previous = null): WandererApiException
    {
        $msg = sprintf('Wanderer %s %s returned status %s', $method, $path, $status ?? 'unknown');

        return match ($status) {
            401 => new BadApiKeyException($msg, $status, $previous),
            404 => new NotFoundException($msg, $status, $previous),
            default => new WandererApiException($msg, $status, $previous),
        };
    }

    /**
     * @param  int[]  $retryStatuses
     * @return callable Middleware to push onto a HandlerStack.
     */
    public static function retryMiddleware(int $max, float $backoff, array $retryStatuses): callable
    {
        return Middleware::retry(
            function (int $retries, RequestInterface $_req, ?ResponseInterface $response = null, ?\Throwable $reason = null) use ($max, $retryStatuses) {
                if ($retries >= $max) {
                    return false;
                }
                // Retry on transport failures.
                if ($reason instanceof ConnectException) {
                    return true;
                }
                // Retry on retryable statuses.
                if ($response !== null && in_array($response->getStatusCode(), $retryStatuses, true)) {
                    return true;
                }
                return false;
            },
            fn (int $retries) => (int) ($backoff * 1000 * (2 ** $retries)),
        );
    }
}
