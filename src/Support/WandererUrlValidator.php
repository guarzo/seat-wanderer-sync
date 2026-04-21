<?php

namespace Guarzo\Seat\WandererSync\Support;

use InvalidArgumentException;

final class WandererUrlValidator
{
    private const BLOCKED_HOSTS = ['localhost', '0.0.0.0', 'local'];

    /**
     * Validate a Wanderer URL and return a normalized form (trailing slashes removed).
     *
     * Rejects:
     *  - empty input
     *  - schemes other than http or https
     *  - missing host
     *  - `localhost`, `0.0.0.0`, `local`
     *  - any IP in loopback, private, or link-local ranges (IPv4 or IPv6)
     *
     * @throws InvalidArgumentException on any rejection.
     */
    public static function validate(string $url): string
    {
        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty');
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                "Invalid URL scheme '{$parsed['scheme']}'. Only http and https are allowed."
            );
        }

        if (empty($parsed['host'])) {
            throw new InvalidArgumentException('URL must include a hostname');
        }

        $host = strtolower($parsed['host']);

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidArgumentException("Access to {$host} is not allowed");
        }

        // Strip IPv6 brackets for FILTER_VALIDATE_IP.
        $ipCandidate = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;

        if (filter_var($ipCandidate, FILTER_VALIDATE_IP)) {
            // filter_var with FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE returns false
            // for private/reserved addresses — that's our rejection signal.
            if (!filter_var(
                $ipCandidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                throw new InvalidArgumentException(
                    "Access to {$ipCandidate} is not allowed (private/loopback/link-local)"
                );
            }
        }

        return rtrim($url, '/');
    }
}
