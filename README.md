# seat-wanderer-sync

A [SeAT](https://github.com/eveseat/seat) plugin that synchronizes SeAT roles to
[Wanderer](https://wanderer.ltd/) access control lists (ACLs).

This is a fork of
[recursivetree/seat-wanderer-access-sync](https://github.com/eveseat-plugins/seat-wanderer-access-sync)
with reliability, safety, and maintainability improvements, published under a new
package name to avoid conflicts with the upstream.

## Installation

Follow the standard
[SeAT community-package installation steps](https://eveseat.github.io/docs/community_packages/).

The package name is `guarzo/seat-wanderer-sync`.

## Configuration

Optional overrides in your SeAT `.env` or Laravel config:

| Setting | Default | Description |
|---|---|---|
| `wanderer-sync.timeout` | `10` | HTTP timeout (seconds) per Wanderer API call. |
| `wanderer-sync.retry_total` | `3` | Retries on transient failures. |
| `wanderer-sync.retry_backoff` | `0.5` | Backoff factor; delay = `backoff * 2^n`. |
| `wanderer-sync.retry_status_codes` | `[500, 502, 503, 504, 429]` | Statuses that trigger a retry. |
| `wanderer-sync.acl_name_cache_ttl` | `600` | Seconds to cache the Wanderer ACL name lookup for the settings UI. |

## Usage

Navigate to `Settings → Wanderer Sync` in your SeAT UI:

1. Add one or more **Wanderer access lists** (URL + ACL UUID + token).
2. Map **SeAT roles** to access lists. Any SeAT user assigned to a mapped role has
   all their authorized characters synced to the corresponding Wanderer ACL once
   per hour.

Tokens are write-only: to rotate a token, delete and re-create the access list.

## Why not use seat-connector?

See the upstream README. In short: Wanderer's ACL model is per-character (not
per-registered-user), which doesn't fit cleanly into the seat-connector driver
contract.

## Development

Requires PHP 8.1+ and Composer.

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
composer validate --strict
```
