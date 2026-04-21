# Modernize seat-wanderer-sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fork `recursivetree/seat-wanderer-access-sync` to `guarzo/seat-wanderer-sync`, re-identified per the SeAT dev guide, with retry/timeout/URL-validated HTTP, typed exceptions, unit tests, CI, write-only tokens, ACL-name display, de-duplicated mappings/instances, and a role-deletion observer. Algorithm preserved (sync SeAT roles to Wanderer ACL members hourly); one bad character no longer kills the run.

**Architecture:** Service-layer refactor. Thin controller → `MappingService`. Thin job → `SyncService` → `UserCharacterResolver` + `WandererClient`. All external I/O isolated behind `WandererClient` with typed exceptions. Unit tests mock the client and resolver. `MappingService` tested with minimal `orchestra/testbench` + in-memory SQLite.

**Tech Stack:** PHP 8.1+, Laravel 10, SeAT 5 (`eveseat/web ^5.0`), Guzzle 7, PHPUnit 10, PHPStan 1.x, Mockery 1.6, orchestra/testbench 8.

**Reference spec:** `docs/superpowers/specs/2026-04-21-modernize-seat-wanderer-sync-design.md`

---

## Execution order

Tasks are dependency-ordered. Each produces a green-test state so the next task can build on it.

1. Tooling (composer, phpunit, phpstan, bootstrap, CI)
2. Wipe upstream PHP source files (keep git history via single clean commit)
3. `Support\TokenSanitizer`
4. `Support\WandererUrlValidator`
5. `Support\Outcome` + `Support\SyncResult`
6. `Exceptions\*`
7. Config files (`Config/config.php`, `Config/permissions.php`, `Config/sidebar.php`)
8. `Driver\WandererClient` (with custom retry middleware)
9. Consolidated migration
10. `Models\WandererAccessListInstance` + `Models\WandererAccessListRole`
11. `Services\UserCharacterResolver` (interface + Eloquent impl)
12. `Services\SyncService`
13. `Services\MappingService` (Testbench)
14. `Observers\RoleObserver`
15. `Jobs\UpdateWandererInstance`
16. `Http\Controllers\SettingsController` + `Http\routes.php`
17. `resources/views/list.blade.php` + `resources/lang/en/settings.php`
18. `database/seeders/ScheduleSeeder.php`
19. `WandererSyncServiceProvider` (wires everything)
20. Update `README.md`, remove `notes.txt`
21. Full verification (PHPUnit + PHPStan + composer validate)

---

## Task 1: Tooling (composer, phpunit, phpstan, bootstrap, CI)

**Files:**
- Modify: `composer.json` (full rewrite)
- Create: `phpunit.xml`
- Create: `phpstan.neon`
- Create: `tests/bootstrap.php`
- Create: `.github/workflows/ci.yml`
- Modify: `.gitignore`

- [ ] **Step 1.1: Rewrite `composer.json`**

Overwrite with:

```json
{
  "name": "guarzo/seat-wanderer-sync",
  "description": "Sync SeAT roles to Wanderer ACLs",
  "type": "seat-plugin",
  "license": "GPL-2.0-or-later",
  "authors": [
    { "name": "guarzo", "email": "guarzo.eve@gmail.com" }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true,
  "require": {
    "php": "^8.1",
    "laravel/framework": "^10.0",
    "eveseat/web": "^5.0",
    "eveseat/eveapi": "^5.0",
    "eveseat/services": "^5.0",
    "guzzlehttp/guzzle": "^7.5"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "phpstan/phpstan": "^1.10",
    "nunomaduro/larastan": "^2.9",
    "mockery/mockery": "^1.6",
    "orchestra/testbench": "^8.0"
  },
  "autoload": {
    "psr-4": {
      "Guarzo\\Seat\\WandererSync\\": "src/",
      "Guarzo\\Seat\\WandererSync\\Seeders\\": "src/database/seeders/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Guarzo\\Seat\\WandererSync\\Tests\\": "tests/"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "Guarzo\\Seat\\WandererSync\\WandererSyncServiceProvider"
      ]
    }
  }
}
```

- [ ] **Step 1.2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
        <exclude>
            <directory>src/database/migrations</directory>
            <directory>src/resources</directory>
        </exclude>
    </source>
</phpunit>
```

- [ ] **Step 1.3: Create `phpstan.neon`**

```neon
includes:
    - vendor/nunomaduro/larastan/extension.neon

parameters:
    level: 5
    paths:
        - src
        - tests
    excludePaths:
        - src/resources/*
        - src/database/migrations/*
    ignoreErrors:
        # SeAT base classes from eveseat/services are not autoloaded during analysis; ignore.
        - '#Call to an undefined method Seat\\Services\\Models\\ExtensibleModel.*#'
        - '#Class Seat\\.*not found#'
```

- [ ] **Step 1.4: Create `tests/bootstrap.php`**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 1.5: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ["8.1", "8.2", "8.3"]
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, dom, fileinfo, pdo, sqlite
          coverage: none
          tools: composer:v2

      - name: Validate composer.json
        run: composer validate --strict

      - name: Cache composer dependencies
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ matrix.php }}-${{ hashFiles('composer.json') }}

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: PHPUnit
        run: vendor/bin/phpunit

      - name: PHPStan
        run: vendor/bin/phpstan analyse
```

- [ ] **Step 1.6: Extend `.gitignore`**

Append these lines to existing `.gitignore`:

```
.phpunit.cache/
.phpunit.result.cache
.phpstan.cache/
```

- [ ] **Step 1.7: Install dependencies and verify tooling**

Run:
```bash
composer install
```
Expected: all deps resolve, no errors. Installs Guzzle, PHPUnit, PHPStan, Mockery, Testbench.

Then verify:
```bash
vendor/bin/phpunit --version
vendor/bin/phpstan --version
composer validate --strict
```
Expected: versions print; `composer validate` says `./composer.json is valid`.

- [ ] **Step 1.8: Commit**

```bash
git add composer.json phpunit.xml phpstan.neon tests/ .github/ .gitignore
git commit -m "chore: set up composer, phpunit, phpstan, CI for fork"
```

---

## Task 2: Wipe upstream PHP source files

**Rationale:** The entire plugin is being rewritten under a new namespace. Delete the old PHP sources in one clean commit so subsequent tasks build up from empty. Preserves history per-file.

**Files:**
- Delete: `src/WandererAccessSyncServiceProvider.php`
- Delete: `src/Driver/WandererAccessList.php`
- Delete: `src/Http/Controllers/SettingsController.php`
- Delete: `src/Http/routes.php`
- Delete: `src/Jobs/UpdateWandererInstance.php`
- Delete: `src/Models/WandererAccessListInstance.php`
- Delete: `src/Models/WandererAccessListRole.php`
- Delete: `src/Config/permissions.php`
- Delete: `src/Config/sidebar.php`
- Delete: `src/database/migrations/2025_08_27_000001_create_acl_role_table.php`
- Delete: `src/database/migrations/2025_08_31_000002_create_wanderer_acl_table.php`
- Delete: `src/database/seeders/ScheduleSeeder.php`
- Delete: `src/resources/views/list.blade.php`
- Delete: `src/resources/lang/en/settings.php`
- Delete: `notes.txt`

- [ ] **Step 2.1: Delete files**

Run:
```bash
git rm src/WandererAccessSyncServiceProvider.php \
       src/Driver/WandererAccessList.php \
       src/Http/Controllers/SettingsController.php \
       src/Http/routes.php \
       src/Jobs/UpdateWandererInstance.php \
       src/Models/WandererAccessListInstance.php \
       src/Models/WandererAccessListRole.php \
       src/Config/permissions.php \
       src/Config/sidebar.php \
       src/database/migrations/2025_08_27_000001_create_acl_role_table.php \
       src/database/migrations/2025_08_31_000002_create_wanderer_acl_table.php \
       src/database/seeders/ScheduleSeeder.php \
       src/resources/views/list.blade.php \
       src/resources/lang/en/settings.php \
       notes.txt
```

- [ ] **Step 2.2: Verify empty `src/` has only empty directories left**

Run: `find src -type f`
Expected: no output (no files).

- [ ] **Step 2.3: Commit**

```bash
git commit -m "chore: remove upstream sources ahead of fork rewrite"
```

---

## Task 3: `Support\TokenSanitizer`

**Files:**
- Create: `src/Support/TokenSanitizer.php`
- Create: `tests/Unit/Support/TokenSanitizerTest.php`

- [ ] **Step 3.1: Write failing test**

Create `tests/Unit/Support/TokenSanitizerTest.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\TokenSanitizer;
use PHPUnit\Framework\TestCase;

final class TokenSanitizerTest extends TestCase
{
    public function test_null_returns_none_literal(): void
    {
        $this->assertSame('None', TokenSanitizer::maskApiKey(null));
    }

    public function test_empty_string_returns_none_literal(): void
    {
        $this->assertSame('None', TokenSanitizer::maskApiKey(''));
    }

    public function test_short_string_is_all_stars(): void
    {
        $this->assertSame('****', TokenSanitizer::maskApiKey('abcd'));
        $this->assertSame('**', TokenSanitizer::maskApiKey('ab'));
    }

    public function test_long_string_shows_last_four_chars(): void
    {
        $this->assertSame('***f456', TokenSanitizer::maskApiKey('abc123def456'));
    }

    public function test_visible_chars_argument_controls_suffix_length(): void
    {
        $this->assertSame('***56', TokenSanitizer::maskApiKey('abc123def456', 2));
    }
}
```

- [ ] **Step 3.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Support/TokenSanitizerTest.php
```
Expected: FAIL with "Class Guarzo\\Seat\\WandererSync\\Support\\TokenSanitizer not found".

- [ ] **Step 3.3: Implement `TokenSanitizer`**

Create `src/Support/TokenSanitizer.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Support;

final class TokenSanitizer
{
    /**
     * Mask an API key for safe logging or UI display.
     *
     * @param  string|null  $apiKey
     * @param  int  $visibleChars  Number of trailing characters to leave visible.
     * @return string  'None' for empty/null; all-star mask for short input; '***<suffix>' otherwise.
     */
    public static function maskApiKey(?string $apiKey, int $visibleChars = 4): string
    {
        if ($apiKey === null || $apiKey === '') {
            return 'None';
        }

        if (strlen($apiKey) <= $visibleChars) {
            return str_repeat('*', strlen($apiKey));
        }

        return '***' . substr($apiKey, -$visibleChars);
    }
}
```

- [ ] **Step 3.4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Support/TokenSanitizerTest.php
```
Expected: PASS (5 tests, all green).

- [ ] **Step 3.5: Commit**

```bash
git add src/Support/TokenSanitizer.php tests/Unit/Support/TokenSanitizerTest.php
git commit -m "feat: add TokenSanitizer for masking API keys in logs and UI"
```

---

## Task 4: `Support\WandererUrlValidator`

**Files:**
- Create: `src/Support/WandererUrlValidator.php`
- Create: `tests/Unit/Support/WandererUrlValidatorTest.php`

- [ ] **Step 4.1: Write failing test**

Create `tests/Unit/Support/WandererUrlValidatorTest.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WandererUrlValidatorTest extends TestCase
{
    public function test_accepts_https_public_host(): void
    {
        $this->assertSame(
            'https://wanderer.ltd',
            WandererUrlValidator::validate('https://wanderer.ltd')
        );
    }

    public function test_accepts_http_public_host(): void
    {
        $this->assertSame(
            'http://wanderer.example.com',
            WandererUrlValidator::validate('http://wanderer.example.com')
        );
    }

    public function test_strips_trailing_slashes(): void
    {
        $this->assertSame(
            'https://wanderer.ltd',
            WandererUrlValidator::validate('https://wanderer.ltd///')
        );
    }

    public function test_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('ftp://wanderer.ltd');
    }

    public function test_rejects_missing_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('https://');
    }

    public function test_rejects_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://localhost');
    }

    public function test_rejects_loopback_ipv4(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://127.0.0.1');
    }

    public function test_rejects_private_network_10_0_0_0(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://10.1.2.3');
    }

    public function test_rejects_private_network_192_168(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://192.168.1.1');
    }

    public function test_rejects_link_local(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://169.254.1.1');
    }

    public function test_rejects_ipv6_loopback(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WandererUrlValidator::validate('http://[::1]');
    }
}
```

- [ ] **Step 4.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Support/WandererUrlValidatorTest.php
```
Expected: FAIL with "Class ... WandererUrlValidator not found".

- [ ] **Step 4.3: Implement `WandererUrlValidator`**

Create `src/Support/WandererUrlValidator.php`:

```php
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
```

- [ ] **Step 4.4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Support/WandererUrlValidatorTest.php
```
Expected: PASS (12 tests).

- [ ] **Step 4.5: Commit**

```bash
git add src/Support/WandererUrlValidator.php tests/Unit/Support/WandererUrlValidatorTest.php
git commit -m "feat: add WandererUrlValidator to block SSRF targets"
```

---

## Task 5: `Support\Outcome` and `Support\SyncResult`

**Files:**
- Create: `src/Support/Outcome.php`
- Create: `src/Support/SyncResult.php`
- Create: `tests/Unit/Support/OutcomeTest.php`
- Create: `tests/Unit/Support/SyncResultTest.php`

- [ ] **Step 5.1: Write failing test for `Outcome`**

Create `tests/Unit/Support/OutcomeTest.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\Outcome;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    public function test_success(): void
    {
        $o = Outcome::success();
        $this->assertTrue($o->isSuccess());
        $this->assertFalse($o->isExisted());
        $this->assertFalse($o->isError());
        $this->assertNull($o->reasonKey());
    }

    public function test_existed(): void
    {
        $o = Outcome::existed();
        $this->assertFalse($o->isSuccess());
        $this->assertTrue($o->isExisted());
        $this->assertFalse($o->isError());
        $this->assertNull($o->reasonKey());
    }

    public function test_error_carries_reason_key(): void
    {
        $o = Outcome::error('url_invalid');
        $this->assertFalse($o->isSuccess());
        $this->assertFalse($o->isExisted());
        $this->assertTrue($o->isError());
        $this->assertSame('url_invalid', $o->reasonKey());
    }
}
```

- [ ] **Step 5.2: Write failing test for `SyncResult`**

Create `tests/Unit/Support/SyncResultTest.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Tests\Unit\Support;

use Guarzo\Seat\WandererSync\Support\SyncResult;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function test_no_op_is_zero_across_the_board(): void
    {
        $r = SyncResult::noOp();
        $this->assertSame(0, $r->added);
        $this->assertSame(0, $r->removed);
        $this->assertSame([], $r->failed);
    }

    public function test_carries_counts_and_failed_ids(): void
    {
        $r = new SyncResult(added: 3, removed: 2, failed: [42, 43]);
        $this->assertSame(3, $r->added);
        $this->assertSame(2, $r->removed);
        $this->assertSame([42, 43], $r->failed);
    }

    public function test_summary_format(): void
    {
        $r = new SyncResult(added: 3, removed: 2, failed: [42]);
        $this->assertSame('added=3 removed=2 failed=1', $r->summary());
    }
}
```

- [ ] **Step 5.3: Run tests to verify they fail**

```bash
vendor/bin/phpunit tests/Unit/Support/OutcomeTest.php tests/Unit/Support/SyncResultTest.php
```
Expected: FAIL with "Class ... not found" for both.

- [ ] **Step 5.4: Implement `Outcome`**

Create `src/Support/Outcome.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Support;

final class Outcome
{
    private function __construct(
        private readonly string $kind,
        private readonly ?string $reasonKey,
    ) {}

    public static function success(): self { return new self('success', null); }
    public static function existed(): self { return new self('existed', null); }
    public static function error(string $reasonKey): self { return new self('error', $reasonKey); }

    public function isSuccess(): bool { return $this->kind === 'success'; }
    public function isExisted(): bool { return $this->kind === 'existed'; }
    public function isError(): bool { return $this->kind === 'error'; }
    public function reasonKey(): ?string { return $this->reasonKey; }
}
```

- [ ] **Step 5.5: Implement `SyncResult`**

Create `src/Support/SyncResult.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Support;

final class SyncResult
{
    /** @param  int[]  $failed */
    public function __construct(
        public readonly int $added = 0,
        public readonly int $removed = 0,
        public readonly array $failed = [],
    ) {}

    public static function noOp(): self { return new self(); }

    public function summary(): string
    {
        return sprintf('added=%d removed=%d failed=%d', $this->added, $this->removed, count($this->failed));
    }
}
```

- [ ] **Step 5.6: Run tests to verify they pass**

```bash
vendor/bin/phpunit tests/Unit/Support/
```
Expected: PASS (Outcome: 3 tests; SyncResult: 3 tests; plus TokenSanitizer + WandererUrlValidator from prior tasks).

- [ ] **Step 5.7: Commit**

```bash
git add src/Support/Outcome.php src/Support/SyncResult.php tests/Unit/Support/OutcomeTest.php tests/Unit/Support/SyncResultTest.php
git commit -m "feat: add Outcome and SyncResult value objects"
```

---

## Task 6: Exception hierarchy

**Files:**
- Create: `src/Exceptions/WandererApiException.php`
- Create: `src/Exceptions/BadApiKeyException.php`
- Create: `src/Exceptions/NotFoundException.php`

(No dedicated tests; these are trivial and will be exercised by `WandererClientTest` and `SyncServiceTest` in later tasks.)

- [ ] **Step 6.1: Create `WandererApiException`**

Create `src/Exceptions/WandererApiException.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Exceptions;

use RuntimeException;
use Throwable;

class WandererApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
```

- [ ] **Step 6.2: Create `BadApiKeyException`**

Create `src/Exceptions/BadApiKeyException.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Exceptions;

class BadApiKeyException extends WandererApiException {}
```

- [ ] **Step 6.3: Create `NotFoundException`**

Create `src/Exceptions/NotFoundException.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Exceptions;

class NotFoundException extends WandererApiException {}
```

- [ ] **Step 6.4: Verify autoloader finds them**

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; new Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException('x', 401);" && echo OK
```
Expected: `OK`.

- [ ] **Step 6.5: Commit**

```bash
git add src/Exceptions/
git commit -m "feat: add typed Wanderer API exception hierarchy"
```

---

## Task 7: Config files (plugin config, permissions, sidebar)

**Files:**
- Create: `src/Config/config.php`
- Create: `src/Config/permissions.php`
- Create: `src/Config/sidebar.php`

(No tests — these are static data.)

- [ ] **Step 7.1: Create `src/Config/config.php`**

```php
<?php

return [
    'timeout' => 10,
    'retry_total' => 3,
    'retry_backoff' => 0.5,
    'retry_status_codes' => [500, 502, 503, 504, 429],
    'acl_name_cache_ttl' => 600,
];
```

- [ ] **Step 7.2: Create `src/Config/permissions.php`**

```php
<?php

return [
    'edit' => [
        'label' => 'wanderer-sync::settings.permission_edit',
        'description' => 'wanderer-sync::settings.permission_edit_description',
    ],
];
```

- [ ] **Step 7.3: Create `src/Config/sidebar.php`**

```php
<?php

return [
    'wanderer-sync' => [
        'label' => 'wanderer-sync::settings.sidebar',
        'name' => 'wanderer-sync::settings.sidebar',
        'icon' => 'fas fa-map',
        'route' => 'wanderer-sync::settings',
        'route_segment' => 'wanderer-sync',
        'permission' => 'wanderer-sync.edit',
    ],
];
```

- [ ] **Step 7.4: Commit**

```bash
git add src/Config/
git commit -m "feat: add plugin, permissions, sidebar config under wanderer-sync namespace"
```

---

## Task 8: `Driver\WandererClient` with custom retry middleware

**Files:**
- Create: `src/Driver/WandererClient.php`
- Create: `tests/Unit/Driver/WandererClientTest.php`

- [ ] **Step 8.1: Write failing test**

Create `tests/Unit/Driver/WandererClientTest.php`:

```php
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
```

- [ ] **Step 8.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Driver/WandererClientTest.php
```
Expected: FAIL with "Class ... WandererClient not found".

- [ ] **Step 8.3: Implement `WandererClient`**

Create `src/Driver/WandererClient.php`:

```php
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

        logger()?->debug(sprintf(
            '[seat-wanderer-sync] [%d %s] %s /%s (token %s)',
            $status,
            $response->getReasonPhrase(),
            $method,
            ltrim($path, '/'),
            TokenSanitizer::maskApiKey($this->token),
        ));

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
```

- [ ] **Step 8.4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Driver/WandererClientTest.php
```
Expected: PASS (10 tests).

- [ ] **Step 8.5: Commit**

```bash
git add src/Driver/WandererClient.php tests/Unit/Driver/WandererClientTest.php
git commit -m "feat: add WandererClient with retry, timeouts, typed exceptions"
```

---

## Task 9: Consolidated migration

**Files:**
- Create: `src/database/migrations/2026_04_21_000001_create_guarzo_wanderer_sync_tables.php`

(No tests — migrations are exercised via `MappingServiceTest` in Task 13.)

- [ ] **Step 9.1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guarzo_wanderer_sync_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wanderer_url');
            $table->uuid('access_list_id');
            $table->string('access_list_token');
            $table->timestamps();

            $table->unique(
                ['wanderer_url', 'access_list_id'],
                'guarzo_wanderer_sync_instances_unique'
            );
        });

        Schema::create('guarzo_wanderer_sync_role_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('role_id')->unsigned();
            $table->unsignedBigInteger('wanderer_instance_id');
            $table->timestamps();

            $table->unique(
                ['role_id', 'wanderer_instance_id'],
                'guarzo_wanderer_sync_role_mappings_unique'
            );

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->foreign('wanderer_instance_id')
                ->references('id')->on('guarzo_wanderer_sync_instances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarzo_wanderer_sync_role_mappings');
        Schema::dropIfExists('guarzo_wanderer_sync_instances');
    }
};
```

- [ ] **Step 9.2: Commit**

```bash
git add src/database/migrations/
git commit -m "feat: consolidated migration for guarzo_wanderer_sync_{instances,role_mappings}"
```

---

## Task 10: Models

**Files:**
- Create: `src/Models/WandererAccessListInstance.php`
- Create: `src/Models/WandererAccessListRole.php`

(No dedicated unit tests; behavior is covered by `SyncServiceTest` and `MappingServiceTest` in later tasks. The `client()` factory and `aclName()` caching are exercised indirectly.)

- [ ] **Step 10.1: Create `WandererAccessListInstance`**

```php
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
```

- [ ] **Step 10.2: Create `WandererAccessListRole`**

```php
<?php

namespace Guarzo\Seat\WandererSync\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Services\Models\ExtensibleModel;
use Seat\Web\Models\Acl\Role;

/**
 * @property int $id
 * @property int $role_id
 * @property int $wanderer_instance_id
 * @property-read Role $role
 * @property-read WandererAccessListInstance $accessList
 */
class WandererAccessListRole extends ExtensibleModel
{
    protected $table = 'guarzo_wanderer_sync_role_mappings';

    protected $fillable = ['role_id', 'wanderer_instance_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function accessList(): BelongsTo
    {
        return $this->belongsTo(WandererAccessListInstance::class, 'wanderer_instance_id', 'id');
    }
}
```

- [ ] **Step 10.3: Commit**

```bash
git add src/Models/
git commit -m "feat: add Instance and RoleMapping Eloquent models with client factory"
```

---

## Task 11: `Services\UserCharacterResolver` (interface + Eloquent impl)

**Files:**
- Create: `src/Services/UserCharacterResolver.php`
- Create: `src/Services/EloquentUserCharacterResolver.php`

(No dedicated unit test — the Eloquent impl is covered indirectly via `MappingServiceTest` migrations and by being mocked in `SyncServiceTest`. Testing the raw SQL without a populated SeAT fixture set is low-value.)

- [ ] **Step 11.1: Create the interface**

Create `src/Services/UserCharacterResolver.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Illuminate\Support\Collection;

interface UserCharacterResolver
{
    /**
     * Return the de-duplicated set of character IDs that should be on the given instance's ACL,
     * based on the currently-configured SeAT role mappings.
     *
     * @return Collection<int, int>
     */
    public function allowedCharacterIdsForInstance(WandererAccessListInstance $instance): Collection;
}
```

- [ ] **Step 11.2: Create the Eloquent implementation**

Create `src/Services/EloquentUserCharacterResolver.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentUserCharacterResolver implements UserCharacterResolver
{
    public function allowedCharacterIdsForInstance(WandererAccessListInstance $instance): Collection
    {
        // SELECT DISTINCT rt.character_id
        // FROM refresh_tokens rt
        // JOIN role_user ru ON ru.user_id = rt.user_id
        // JOIN guarzo_wanderer_sync_role_mappings m ON m.role_id = ru.role_id
        // WHERE m.wanderer_instance_id = ?
        return DB::table('refresh_tokens')
            ->join('role_user', 'role_user.user_id', '=', 'refresh_tokens.user_id')
            ->join('guarzo_wanderer_sync_role_mappings', 'guarzo_wanderer_sync_role_mappings.role_id', '=', 'role_user.role_id')
            ->where('guarzo_wanderer_sync_role_mappings.wanderer_instance_id', $instance->id)
            ->distinct()
            ->pluck('refresh_tokens.character_id')
            ->map(fn ($id) => (int) $id);
    }
}
```

- [ ] **Step 11.3: Commit**

```bash
git add src/Services/UserCharacterResolver.php src/Services/EloquentUserCharacterResolver.php
git commit -m "feat: add UserCharacterResolver abstraction + Eloquent implementation"
```

---

## Task 12: `Services\SyncService`

**Files:**
- Create: `src/Services/SyncService.php`
- Create: `tests/Unit/Services/SyncServiceTest.php`

- [ ] **Step 12.1: Write failing test**

Create `tests/Unit/Services/SyncServiceTest.php`:

```php
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
```

- [ ] **Step 12.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Services/SyncServiceTest.php
```
Expected: FAIL with "Class ... SyncService not found".

- [ ] **Step 12.3: Implement `SyncService`**

Create `src/Services/SyncService.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException;
use Guarzo\Seat\WandererSync\Exceptions\NotFoundException;
use Guarzo\Seat\WandererSync\Exceptions\WandererApiException;
use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Support\SyncResult;
use Psr\Log\LoggerInterface;

final class SyncService
{
    public function __construct(
        private readonly UserCharacterResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncInstance(WandererAccessListInstance $instance): SyncResult
    {
        $allowed = $this->resolver->allowedCharacterIdsForInstance($instance)->unique()->values();

        $client = $instance->client();
        $current = $client->fetchMembers()->unique()->values();

        $toAdd = $allowed->diff($current)->values();
        $toRemove = $current->diff($allowed)->values();

        $removed = 0;
        $failed = [];

        // Removes first.
        foreach ($toRemove as $charId) {
            try {
                $client->removeMember($charId);
                $removed++;
            } catch (NotFoundException) {
                // Already gone — count as success.
                $removed++;
            } catch (BadApiKeyException $e) {
                throw $e;
            } catch (WandererApiException $e) {
                $this->logger->error('Failed to remove character from ACL', [
                    'instance_id' => $instance->id,
                    'character_id' => $charId,
                    'status' => $e->status,
                    'message' => $e->getMessage(),
                ]);
                $failed[] = $charId;
            }
        }

        $added = 0;
        foreach ($toAdd as $charId) {
            try {
                $client->addMember($charId);
                $added++;
            } catch (BadApiKeyException $e) {
                throw $e;
            } catch (WandererApiException $e) {
                $this->logger->error('Failed to add character to ACL', [
                    'instance_id' => $instance->id,
                    'character_id' => $charId,
                    'status' => $e->status,
                    'message' => $e->getMessage(),
                ]);
                $failed[] = $charId;
            }
        }

        return new SyncResult(added: $added, removed: $removed, failed: $failed);
    }
}
```

- [ ] **Step 12.4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Services/SyncServiceTest.php
```
Expected: PASS (7 tests).

- [ ] **Step 12.5: Commit**

```bash
git add src/Services/SyncService.php tests/Unit/Services/SyncServiceTest.php
git commit -m "feat: add SyncService with per-character fault tolerance"
```

---

## Task 13: `Services\MappingService` (Testbench + SQLite)

**Files:**
- Create: `src/Services/MappingService.php`
- Create: `tests/Unit/Services/MappingServiceTest.php`

- [ ] **Step 13.1: Write failing test**

Create `tests/Unit/Services/MappingServiceTest.php`:

```php
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
        ]);
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
```

- [ ] **Step 13.2: Run test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Services/MappingServiceTest.php
```
Expected: FAIL with "Class ... MappingService not found".

- [ ] **Step 13.3: Implement `MappingService`**

Create `src/Services/MappingService.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Guarzo\Seat\WandererSync\Support\Outcome;
use Guarzo\Seat\WandererSync\Support\WandererUrlValidator;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class MappingService
{
    public function createInstance(string $url, string $aclId, string $token): Outcome
    {
        try {
            $normalized = WandererUrlValidator::validate($url);
        } catch (InvalidArgumentException) {
            return Outcome::error('url_invalid');
        }

        $existing = WandererAccessListInstance::query()
            ->where('wanderer_url', $normalized)
            ->where('access_list_id', $aclId)
            ->first();
        if ($existing) {
            return Outcome::existed();
        }

        try {
            WandererAccessListInstance::create([
                'wanderer_url' => $normalized,
                'access_list_id' => $aclId,
                'access_list_token' => $token,
            ]);
        } catch (QueryException $e) {
            // Defensive: unique index race; treat as existed.
            return Outcome::existed();
        }

        return Outcome::success();
    }

    public function deleteInstance(int $instanceId): void
    {
        WandererAccessListInstance::destroy($instanceId);
    }

    public function createMapping(int $roleId, int $instanceId): Outcome
    {
        $existing = WandererAccessListRole::query()
            ->where('role_id', $roleId)
            ->where('wanderer_instance_id', $instanceId)
            ->first();
        if ($existing) {
            return Outcome::existed();
        }

        try {
            WandererAccessListRole::create([
                'role_id' => $roleId,
                'wanderer_instance_id' => $instanceId,
            ]);
        } catch (QueryException) {
            return Outcome::existed();
        }

        return Outcome::success();
    }

    public function deleteMapping(int $mappingId): void
    {
        WandererAccessListRole::destroy($mappingId);
    }
}
```

- [ ] **Step 13.4: Run test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Services/MappingServiceTest.php
```
Expected: PASS (7 tests).

- [ ] **Step 13.5: Commit**

```bash
git add src/Services/MappingService.php tests/Unit/Services/MappingServiceTest.php
git commit -m "feat: add MappingService with URL validation and dedup guards"
```

---

## Task 14: `Observers\RoleObserver`

**Files:**
- Create: `src/Observers/RoleObserver.php`

(No dedicated unit test: behavior is a one-liner delegated to Eloquent. The foreign-key `cascadeOnDelete` provides the real cleanup path; the observer is belt-and-suspenders and gets exercised by running SeAT in integration.)

- [ ] **Step 14.1: Create `RoleObserver`**

```php
<?php

namespace Guarzo\Seat\WandererSync\Observers;

use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Seat\Web\Models\Acl\Role;

final class RoleObserver
{
    public function deleting(Role $role): void
    {
        WandererAccessListRole::where('role_id', $role->id)->delete();
    }
}
```

- [ ] **Step 14.2: Commit**

```bash
git add src/Observers/
git commit -m "feat: add RoleObserver to clean up mappings on SeAT role deletion"
```

---

## Task 15: `Jobs\UpdateWandererInstance` (thin shell)

**Files:**
- Create: `src/Jobs/UpdateWandererInstance.php`

(No unit test: the job is a thin adapter between the Laravel queue and `SyncService`. Sync logic is already covered in `SyncServiceTest`.)

- [ ] **Step 15.1: Create the job**

```php
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

        logger()?->info(sprintf(
            '[seat-wanderer-sync] instance=%d %s',
            $this->instance->id,
            $result->summary(),
        ));
    }
}
```

- [ ] **Step 15.2: Commit**

```bash
git add src/Jobs/
git commit -m "feat: add UpdateWandererInstance job delegating to SyncService"
```

---

## Task 16: `Http\Controllers\SettingsController` + `Http\routes.php`

**Files:**
- Create: `src/Http/Controllers/SettingsController.php`
- Create: `src/Http/routes.php`

(No dedicated unit tests — controllers are thin shells over `MappingService` which is already tested. Integration testing under SeAT is out of scope per the spec.)

- [ ] **Step 16.1: Create `SettingsController`**

Create `src/Http/Controllers/SettingsController.php`:

```php
<?php

namespace Guarzo\Seat\WandererSync\Http\Controllers;

use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Models\WandererAccessListRole;
use Guarzo\Seat\WandererSync\Services\MappingService;
use Guarzo\Seat\WandererSync\Support\Outcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\Acl\Role;

final class SettingsController extends Controller
{
    public function __construct(private readonly MappingService $mappings) {}

    public function list(): View
    {
        return view('wanderer-sync::list', [
            'roles' => WandererAccessListRole::with(['role', 'accessList'])->get(),
            'seat_roles' => Role::all(),
            'wanderer_access_lists' => WandererAccessListInstance::all(),
        ]);
    }

    public function createMapping(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => 'required|integer',
            'acl' => 'required|integer',
        ]);

        if (!WandererAccessListInstance::find($data['acl'])) {
            return back()->with('error', trans('wanderer-sync::settings.acl_not_found'));
        }

        return $this->flash($this->mappings->createMapping($data['role'], $data['acl']));
    }

    public function deleteMapping(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => 'required|integer']);
        $this->mappings->deleteMapping($data['id']);
        return back()->with('success', trans('wanderer-sync::settings.mapping_deleted'));
    }

    public function createWandererAccessList(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => 'required|string',
            'id' => 'required|string',
            'token' => 'required|string',
        ]);

        return $this->flash($this->mappings->createInstance($data['url'], $data['id'], $data['token']));
    }

    public function deleteInstance(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => 'required|integer']);
        $this->mappings->deleteInstance($data['id']);
        return back()->with('success', trans('wanderer-sync::settings.instance_deleted'));
    }

    private function flash(Outcome $outcome): RedirectResponse
    {
        if ($outcome->isSuccess()) {
            return back()->with('success', trans('wanderer-sync::settings.added'));
        }
        if ($outcome->isExisted()) {
            return back()->with('warning', trans('wanderer-sync::settings.already_exists'));
        }
        return back()->with('error', trans("wanderer-sync::settings.{$outcome->reasonKey()}"));
    }
}
```

- [ ] **Step 16.2: Create `routes.php`**

Create `src/Http/routes.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::group([
    'namespace'  => 'Guarzo\Seat\WandererSync\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
    'prefix'     => 'wanderer-sync',
], function () {
    Route::get('/settings', [
        'as'         => 'wanderer-sync::settings',
        'uses'       => 'SettingsController@list',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/mapping', [
        'as'         => 'wanderer-sync::createMapping',
        'uses'       => 'SettingsController@createMapping',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/mapping/delete', [
        'as'         => 'wanderer-sync::deleteMapping',
        'uses'       => 'SettingsController@deleteMapping',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/accesslist', [
        'as'         => 'wanderer-sync::createWandererAccessList',
        'uses'       => 'SettingsController@createWandererAccessList',
        'middleware' => 'can:wanderer-sync.edit',
    ]);

    Route::post('/settings/accesslist/delete', [
        'as'         => 'wanderer-sync::deleteInstance',
        'uses'       => 'SettingsController@deleteInstance',
        'middleware' => 'can:wanderer-sync.edit',
    ]);
});
```

- [ ] **Step 16.3: Commit**

```bash
git add src/Http/
git commit -m "feat: add thin SettingsController + routes under wanderer-sync namespace"
```

---

## Task 17: Views and translations

**Files:**
- Create: `src/resources/views/list.blade.php`
- Create: `src/resources/lang/en/settings.php`

- [ ] **Step 17.1: Create the Blade view**

Create `src/resources/views/list.blade.php`:

```blade
@extends('web::layouts.app')

@section('title', trans('wanderer-sync::settings.title'))
@section('page_header', trans('wanderer-sync::settings.title'))

@section('content')
    <div class="card">
        <div class="card-body">
            <h5>{{ trans('wanderer-sync::settings.create_role_mapping') }}</h5>
            <form action="{{ route('wanderer-sync::createMapping') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="role-sel">{{ trans('wanderer-sync::settings.select_seat_role') }}</label>
                    <select class="form-control" id="role-sel" name="role">
                        @foreach($seat_roles as $role)
                            <option value="{{ $role->id }}">{{ $role->title }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">{{ trans('wanderer-sync::settings.help_mapping_role') }}</small>
                </div>
                <div class="form-group">
                    <label for="acl-sel">{{ trans('wanderer-sync::settings.select_access_list') }}</label>
                    <select class="form-control" id="acl-sel" name="acl">
                        @foreach($wanderer_access_lists as $list)
                            <option value="{{ $list->id }}">{{ $list->aclName() ?? $list->access_list_id }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">{{ trans('wanderer-sync::settings.help_mapping_list') }}</small>
                </div>
                <button type="submit" class="btn btn-primary">{{ trans('wanderer-sync::settings.add') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans('wanderer-sync::settings.role_mapping') }}</h5>
            <table class="table">
                <thead>
                <tr>
                    <th>{{ trans('wanderer-sync::settings.role') }}</th>
                    <th>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 1) }}</th>
                    <th>{{ trans('wanderer-sync::settings.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($roles as $role)
                    <tr>
                        <td>{{ $role->role->title }}</td>
                        <td>{{ $role->accessList->aclName() ?? $role->accessList->access_list_id }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('wanderer-sync::deleteMapping') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $role->id }}">
                                <button class="btn btn-danger btn-sm confirmdelete" type="submit">{{ trans('wanderer-sync::settings.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 2) }}</h5>
            <p class="text-muted small">{{ trans('wanderer-sync::settings.token_write_only_notice') }}</p>
            <form action="{{ route('wanderer-sync::createWandererAccessList') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="wanderer-url">{{ trans('wanderer-sync::settings.wanderer_url') }}</label>
                    <input type="text" class="form-control" id="wanderer-url" name="url" placeholder="{{ trans('wanderer-sync::settings.wanderer_url_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="wanderer-acl-id">{{ trans('wanderer-sync::settings.access_list_id') }}</label>
                    <input type="text" class="form-control" id="wanderer-acl-id" name="id" placeholder="{{ trans('wanderer-sync::settings.access_list_id_placeholder') }}">
                </div>
                <div class="form-group">
                    <label for="wanderer-token">{{ trans('wanderer-sync::settings.access_list_token') }}</label>
                    <input type="password" class="form-control" id="wanderer-token" name="token" placeholder="{{ trans('wanderer-sync::settings.access_list_token_placeholder') }}">
                </div>
                <button type="submit" class="btn btn-primary">{{ trans('wanderer-sync::settings.add') }}</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5>{{ trans_choice('wanderer-sync::settings.wanderer_access_list', 2) }}</h5>
            <table class="table">
                <thead>
                <tr>
                    <th>{{ trans('wanderer-sync::settings.wanderer_url') }}</th>
                    <th>{{ trans('wanderer-sync::settings.acl_name') }}</th>
                    <th>{{ trans('wanderer-sync::settings.access_list_id') }}</th>
                    <th>{{ trans('wanderer-sync::settings.actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($wanderer_access_lists as $access_list)
                    <tr>
                        <td>{{ $access_list->wanderer_url }}</td>
                        <td>{{ $access_list->aclName() ?? '-' }}</td>
                        <td><code>{{ $access_list->access_list_id }}</code></td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('wanderer-sync::deleteInstance') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $access_list->id }}">
                                <button class="btn btn-danger btn-sm confirmdelete" type="submit">{{ trans('wanderer-sync::settings.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@stop
```

- [ ] **Step 17.2: Create the translation file**

Create `src/resources/lang/en/settings.php`:

```php
<?php

return [
    'permission_edit' => 'Edit Wanderer Synchronization Settings',
    'permission_edit_description' => 'Allows a user to edit wanderer synchronization settings.',
    'sidebar' => 'Wanderer Sync',
    'title' => 'Wanderer Sync Settings',

    'wanderer_url_placeholder' => 'https://your.wanderer.instance.com',
    'access_list_id_placeholder' => 'Enter your access list id',
    'access_list_token_placeholder' => 'Enter your access list token',

    'actions' => 'Actions',
    'delete' => 'Delete',
    'add' => 'Add',
    'role' => 'Role',
    'role_mapping' => 'Role Mapping',
    'wanderer_url' => 'Wanderer URL',
    'access_list_id' => 'Access List ID',
    'access_list_token' => 'Access List Token',
    'acl_name' => 'Name',
    'wanderer_access_list' => 'Wanderer Access List|Wanderer Access Lists',

    'help_mapping_list' => 'If there are no access lists, you can add one below before creating a mapping.',
    'help_mapping_role' => 'You can manage roles under Settings -> Access Management.',
    'select_access_list' => 'Select Wanderer Access List',
    'select_seat_role' => 'Select SeAT Role',
    'create_role_mapping' => 'Create Role Mapping',

    'token_write_only_notice' => 'Tokens are write-only. To rotate a token, delete the access list and re-create it.',
    'added' => 'Added successfully.',
    'already_exists' => 'That entry already exists. No changes were made.',
    'mapping_deleted' => 'Mapping deleted.',
    'instance_deleted' => 'Access list deleted.',
    'url_invalid' => 'The Wanderer URL is invalid or points to a blocked network.',
    'acl_not_found' => 'That access list does not exist.',
];
```

- [ ] **Step 17.3: Commit**

```bash
git add src/resources/
git commit -m "feat: wanderer-sync views and English translations; tokens no longer displayed"
```

---

## Task 18: Schedule seeder

**Files:**
- Create: `src/database/seeders/ScheduleSeeder.php`

- [ ] **Step 18.1: Create the seeder**

```php
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
```

- [ ] **Step 18.2: Commit**

```bash
git add src/database/seeders/
git commit -m "feat: schedule seeder for wanderer-sync:run (hourly, random minute)"
```

---

## Task 19: `WandererSyncServiceProvider`

**Files:**
- Create: `src/WandererSyncServiceProvider.php`

- [ ] **Step 19.1: Create the service provider**

```php
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
```

- [ ] **Step 19.2: Verify autoloader picks up the service provider class name from composer.json**

Run:
```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; echo class_exists('Guarzo\\Seat\\WandererSync\\WandererSyncServiceProvider') ? 'OK' : 'MISSING';"
```
Expected: `OK`.

- [ ] **Step 19.3: Commit**

```bash
git add src/WandererSyncServiceProvider.php
git commit -m "feat: WandererSyncServiceProvider ties bindings, observer, routes, artisan together"
```

---

## Task 20: Update README and remove notes.txt

**Files:**
- Modify: `README.md`

(`notes.txt` was deleted in Task 2.)

- [ ] **Step 20.1: Overwrite `README.md`**

```markdown
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
````

- [ ] **Step 20.2: Commit**

```bash
git add README.md
git commit -m "docs: update README for guarzo/seat-wanderer-sync fork"
```

---

## Task 21: Full verification

**Files:** (none — verification only)

- [ ] **Step 21.1: Run the full PHPUnit suite**

```bash
vendor/bin/phpunit
```
Expected: PASS. All tests from Tasks 3, 4, 5, 8, 12, 13 run green. Total: ~44 tests.

- [ ] **Step 21.2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```
Expected: `[OK] No errors` (or only pre-listed ignored errors).

- [ ] **Step 21.3: Validate composer**

```bash
composer validate --strict
```
Expected: `./composer.json is valid`.

- [ ] **Step 21.4: Verify all expected files exist**

```bash
find src tests .github -type f | sort
```
Expected output (sorted):
```
.github/workflows/ci.yml
src/Config/config.php
src/Config/permissions.php
src/Config/sidebar.php
src/Driver/WandererClient.php
src/Exceptions/BadApiKeyException.php
src/Exceptions/NotFoundException.php
src/Exceptions/WandererApiException.php
src/Http/Controllers/SettingsController.php
src/Http/routes.php
src/Jobs/UpdateWandererInstance.php
src/Models/WandererAccessListInstance.php
src/Models/WandererAccessListRole.php
src/Observers/RoleObserver.php
src/Services/EloquentUserCharacterResolver.php
src/Services/MappingService.php
src/Services/SyncService.php
src/Services/UserCharacterResolver.php
src/Support/Outcome.php
src/Support/SyncResult.php
src/Support/TokenSanitizer.php
src/Support/WandererUrlValidator.php
src/WandererSyncServiceProvider.php
src/database/migrations/2026_04_21_000001_create_guarzo_wanderer_sync_tables.php
src/database/seeders/ScheduleSeeder.php
src/resources/lang/en/settings.php
src/resources/views/list.blade.php
tests/Unit/Driver/WandererClientTest.php
tests/Unit/Services/MappingServiceTest.php
tests/Unit/Services/SyncServiceTest.php
tests/Unit/Support/OutcomeTest.php
tests/Unit/Support/SyncResultTest.php
tests/Unit/Support/TokenSanitizerTest.php
tests/Unit/Support/WandererUrlValidatorTest.php
tests/bootstrap.php
```

- [ ] **Step 21.5: Verify no orphan upstream references**

```bash
grep -r "RecursiveTree" src tests && echo "FAIL: orphan references" || echo "OK: no orphan references"
grep -r "WandererAccessSync" src tests && echo "FAIL: orphan references" || echo "OK: no orphan references"
grep -r "recursivetree/seat-wanderer-access-sync" src tests && echo "FAIL: orphan references" || echo "OK: no orphan references"
```
Expected: three "OK" lines.

- [ ] **Step 21.6: Sanity-check the integration points against the spec**

Manual checklist (no command; review the modified service provider and the spec's goals section):

- Tokens never appear in the Blade view (`list.blade.php` does not reference `access_list_token`).
- `UpdateWandererInstance` handle() no longer contains any HTTP or diff logic.
- `SettingsController` methods are ~10 lines each and delegate to `MappingService`.
- `ScheduleSeeder::getDeprecatedSchedules()` returns `['wanderer:sync']`.

- [ ] **Step 21.7: (Optional but recommended) run CI-matrix locally**

If local PHP versions are available, verify on each:
```bash
vendor/bin/phpunit
```

- [ ] **Step 21.8: Final commit (if any pending tweaks)**

If Steps 21.1–21.6 required fixes, commit them individually (e.g. `fix: phpstan warning in X`). Otherwise skip.

---

## Completion criteria

The plan is complete when:

1. All 21 tasks are checked off.
2. `vendor/bin/phpunit` is green (~44 tests).
3. `vendor/bin/phpstan analyse` reports no errors (level 5).
4. `composer validate --strict` passes.
5. `grep` in Step 21.5 reports no upstream references.
6. The git log shows one commit per task with conventional prefixes (`chore:`, `feat:`, `docs:`, `fix:`).

The plugin is then ready for a tag and Packagist submission as `guarzo/seat-wanderer-sync`.
