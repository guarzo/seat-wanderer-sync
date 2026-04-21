# Modernize seat-wanderer-sync (fork of seat-wanderer-access-sync)

Date: 2026-04-21
Status: Approved design; ready for implementation plan
Scope: "A" — reliability/safety/maintainability parity + notes.txt product gaps + SeAT-guideline fixes

## Context

`seat-wanderer-access-sync` is a small (~400 LOC, 14 files) PHP/Laravel SeAT plugin by `recursivetree`
that syncs SeAT roles to Wanderer ACLs for EVE Online. It works but is minimally-featured and has
several reliability, safety, and maintainability gaps.

The maintainer (`guarzo`) maintains a parallel Python/Django plugin for Alliance Auth at
`../aa-wanderer-map` that provides the same integration for a different auth application. The Python
project is well-maintained and serves as the behavioral reference for this modernization.

This design forks the plugin under a new identity (`guarzo/seat-wanderer-sync`) so it can be
published without colliding with the upstream package on Packagist.

## Goals

1. The plugin reliably survives transient Wanderer API failures.
2. A dropped/timed-out connection or one bad character does not kill an entire sync run.
3. Tokens are never displayed in the UI and never appear in logs.
4. URL input is validated to reject SSRF targets (localhost, private ranges).
5. Business logic is covered by unit tests and continuous integration.
6. The plugin complies with SeAT plugin development guidelines.
7. The three `notes.txt` items are resolved (display ACL names, de-duplicate mappings, de-duplicate
   instances).

## Non-goals (explicitly out of scope for this pass)

- Full `AccessListRoles` enum (admin/manager/viewer/blocked). Current plugin only assigns `member`
  and that stays.
- Role preservation during cleanup of manually-set admin/manager roles. Not a feature today.
- Caching beyond the ACL-name lookup.
- UI redesign. Keep Bootstrap 4 / SeAT layout.
- Integration tests via `orchestra/testbench` beyond the minimum needed for the in-memory SQLite
  database tests of `MappingService`.
- Translations beyond English.
- Migration chain from the old `seat_wanderer_access_sync_*` tables. Fresh start (no deployed
  data depends on this codebase).
- Bumping SeAT version. Target stays `eveseat/web: ^5.0`, Laravel 10, PHP 8.1+.

## Rename / fork identity

| Thing | Old | New |
|---|---|---|
| Composer package | `recursivetree/seat-wanderer-access-sync` | `guarzo/seat-wanderer-sync` |
| Namespace | `RecursiveTree\Seat\WandererAccessSync\` | `Guarzo\Seat\WandererSync\` |
| Service provider | `WandererAccessSyncServiceProvider` | `WandererSyncServiceProvider` |
| Route / translation / view namespace | `wanderer-access-sync` | `wanderer-sync` |
| DB tables | `seat_wanderer_access_sync_{roles,instances}` | `guarzo_wanderer_sync_{role_mappings,instances}` |
| Laravel config file | (none) | `config/wanderer-sync.php` |
| Artisan command | `wanderer:sync` | `wanderer-sync:run` |
| Sidebar label | "Wanderer Access" | "Wanderer Sync" |
| composer `type` | (unset) | `"type": "seat-plugin"` |

Rationale: fresh identity avoids Packagist collision; renames match SeAT dev-guide conventions
(author-prefixed table names, scoped routes, explicit `seat-plugin` type).

## Architecture

### Module layout

```
src/
├── Config/
│   ├── config.php                            (NEW — merged as 'wanderer-sync')
│   ├── permissions.php                       (unchanged content, renamed keys)
│   └── sidebar.php                           (unchanged content, renamed keys)
├── Driver/
│   └── WandererClient.php                    (REPLACES WandererAccessList; retry/timeout/validation)
├── Exceptions/
│   ├── WandererApiException.php              (base)
│   ├── BadApiKeyException.php                (401)
│   └── NotFoundException.php                 (404)
├── Http/
│   ├── Controllers/SettingsController.php    (thin; delegates to MappingService)
│   └── routes.php                            (namespace updated)
├── Jobs/
│   └── UpdateWandererInstance.php            (thin wrapper around SyncService)
├── Models/
│   ├── WandererAccessListInstance.php        (adds unique index, client() factory, ACL-name cache)
│   └── WandererAccessListRole.php            (adds unique index)
├── Observers/
│   └── RoleObserver.php                      (NEW — cleanup on Role delete)
├── Services/
│   ├── SyncService.php                       (NEW — owns diff-and-apply logic)
│   ├── MappingService.php                    (NEW — extracted from controller)
│   └── UserCharacterResolver.php             (NEW — interface + Eloquent impl)
├── Support/
│   ├── TokenSanitizer.php                    (NEW — mask tokens for logs/UI)
│   ├── WandererUrlValidator.php              (NEW — SSRF guard)
│   ├── Outcome.php                           (NEW — MappingService result value object)
│   └── SyncResult.php                        (NEW — SyncService result value object)
├── database/
│   ├── migrations/
│   │   └── 2026_04_21_000001_create_guarzo_wanderer_sync_tables.php   (single consolidated)
│   └── seeders/
│       └── ScheduleSeeder.php                (command name updated)
├── resources/
│   ├── lang/en/settings.php                  (+ masked-token + dedup strings)
│   └── views/list.blade.php                  (token column removed; ACL-name column added)
└── WandererSyncServiceProvider.php           (registers observer + container bindings + config merge)

tests/
├── Unit/
│   ├── Driver/WandererClientTest.php
│   ├── Services/SyncServiceTest.php
│   ├── Services/MappingServiceTest.php       (minimal Testbench for SQLite)
│   └── Support/
│       ├── TokenSanitizerTest.php
│       └── WandererUrlValidatorTest.php
└── bootstrap.php

.github/workflows/ci.yml
phpunit.xml
phpstan.neon
```

### HTTP client (`Driver\WandererClient`)

Replaces `Driver\WandererAccessList`. Public surface:

```php
final class WandererClient {
    public function __construct(string $url, string $id, string $token, array $config = []);
    public function fetchMembers(): Collection;            // was seedMembers() + getMembers()
    public function fetchAclName(): ?string;               // NEW for UI
    public function addMember(int $characterId): void;     // throws typed
    public function removeMember(int $characterId): void;  // throws typed
}
```

**Behavioral requirements:**

- **Retry with exponential backoff.** Uses Guzzle middleware stack with `RetryMiddleware` or a
  small custom middleware. Retries on 500/502/503/504/429 and connect/read timeouts. Does not
  retry 4xx other than those. Configuration from `config('wanderer-sync.*')`, defaults:
  `retry_total=3`, `retry_backoff=0.5` (delay = backoff × 2^n), `timeout=10`.
- **URL validation** via `Support\WandererUrlValidator` on construction: reject non-http(s),
  empty host, `localhost`, `127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16`, IPv6
  `::1`, `fc00::/7`, `fe80::/10`. Port of the Python reference's `WandererURLValidator`.
- **Typed exceptions** wrapping Guzzle: 401 → `BadApiKeyException`, 404 on member ops →
  `NotFoundException`, other HTTP / transport failures → `WandererApiException` with status code
  and sanitized URL.
- **Logging sanitization.** Every call logs `[method, status, sanitized-url]`. Tokens are never
  logged raw; API-key suffix rendered via `TokenSanitizer::maskApiKey()` (`***xxxx`).
- **Single-phase fetch.** `fetchMembers()` returns the members collection directly. The two-step
  `seedMembers()`/`getMembers()` pattern is collapsed.
- **Construction is side-effect-free** — URL validation only; no network I/O.
- **No 4xx retry, no caching** in the client itself.

### Exception hierarchy

```php
namespace Guarzo\Seat\WandererSync\Exceptions;

class WandererApiException extends \RuntimeException {
    public function __construct(string $msg, public readonly ?int $status = null, ?\Throwable $previous = null);
}
class BadApiKeyException extends WandererApiException {}
class NotFoundException extends WandererApiException {}
```

### Services

#### `SyncService`

Owns the diff-and-apply logic currently inline in `UpdateWandererInstance::handle`.

```php
final class SyncService {
    public function __construct(
        private readonly UserCharacterResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {}
    public function syncInstance(WandererAccessListInstance $instance): SyncResult;
}
```

**Algorithm:**

1. Load mappings for `instance_id`. If none → return `SyncResult::noOp()`.
2. `$allowed = $resolver->allowedCharacterIdsForInstance($instance)` (de-duplicated).
3. `$client = $instance->client()`.
4. `$current = $client->fetchMembers()`.
5. `$toAdd = $allowed->diff($current)`, `$toRemove = $current->diff($allowed)`.
6. Apply: removes first, then adds.
7. **Per-character try/catch**:
   - `NotFoundException` on remove → log warning, count as success (already gone).
   - `BadApiKeyException` → rethrow immediately; do not continue. The key will fail for every
     character and every subsequent sync run; the operator needs to know.
   - Other `WandererApiException` → log error with `character_id` + `instance_id`, add to
     failed list, continue with next character.
   - Non-API exceptions (e.g. bugs, DB errors) rethrow.
8. Return `SyncResult { added: int, removed: int, failed: array<int> }`. `SyncResult` is a
   read-only value object in `Support/SyncResult.php`.

The previous algorithm called `addMember()` for all `allowed` — depending on the `$members`
collection being pre-seeded to skip no-ops. The new version computes the diff up front, doing
strictly fewer HTTP calls and making the behavior testable.

#### `UserCharacterResolver`

```php
interface UserCharacterResolver {
    public function allowedCharacterIdsForInstance(WandererAccessListInstance $instance): Collection;
}

final class EloquentUserCharacterResolver implements UserCharacterResolver {
    // Single JOIN:
    // SELECT DISTINCT refresh_tokens.character_id
    // FROM refresh_tokens
    // JOIN role_user ON role_user.user_id = refresh_tokens.user_id
    // JOIN guarzo_wanderer_sync_role_mappings AS m ON m.role_id = role_user.role_id
    // WHERE m.wanderer_instance_id = ?
}
```

Current code does `role->users()->pluck('id')` in a loop and then queries `RefreshToken`.
Replaced with a single JOIN.

Bound in the service provider:
`$this->app->bind(UserCharacterResolver::class, EloquentUserCharacterResolver::class);`

#### `MappingService`

```php
final class MappingService {
    public function createMapping(int $roleId, int $instanceId): Outcome;
    public function deleteMapping(int $mappingId): void;
    public function createInstance(string $url, string $aclId, string $token): Outcome;
    public function deleteInstance(int $instanceId): void;
}
```

**Guardrails (resolves notes.txt):**

- Duplicate mapping (`role_id`, `wanderer_instance_id` pair) → `firstOrCreate` short-circuit.
  Returns `Outcome::existed()` for a neutral flash message.
- Duplicate instance (`wanderer_url`, `access_list_id` pair) → unique-index violation caught,
  returns `Outcome::existed()`.
- `createInstance` validates URL via `WandererUrlValidator`. Invalid → `Outcome::error('url_invalid')`.

`SettingsController` becomes ~10 lines per action: validate request → call service → flash → redirect.

`Outcome` is a read-only value object in `Support/Outcome.php` with three named constructors:
`Outcome::success()`, `Outcome::existed()`, `Outcome::error(string $reasonKey)`, plus
`isSuccess(): bool`, `isExisted(): bool`, `isError(): bool`, and `reasonKey(): ?string`.
The reason key is a translation key (e.g. `'url_invalid'`), not a human-readable string.

### Observer

```php
final class RoleObserver {
    public function deleting(Role $role): void {
        WandererAccessListRole::where('role_id', $role->id)->delete();
    }
}
```

Registered via `Role::observe(RoleObserver::class)` in `boot()`. Belt-and-suspenders with the
`cascadeOnDelete()` foreign key on `role_id`.

### Models

- `WandererAccessListInstance`: enable timestamps (`public $timestamps = true`), add
  `client(): WandererClient` factory (overridable in tests), add `aclName(): ?string` that calls
  `client()->fetchAclName()` through `Cache` keyed on `guarzo.wanderer_sync.acl_name.{id}` with
  TTL `config('wanderer-sync.acl_name_cache_ttl', 600)`. On cache miss or API error, returns
  `null` and the view falls back to the UUID. (Note: uniqueness is enforced at the schema level,
  see the migration section — the model does not redeclare it.)
- `WandererAccessListRole`: enable timestamps. No other behavioral changes.

### Config (new)

`src/Config/config.php`:

```php
return [
    'timeout' => 10,
    'retry_total' => 3,
    'retry_backoff' => 0.5,
    'retry_status_codes' => [500, 502, 503, 504, 429],
    'acl_name_cache_ttl' => 600,
];
```

Merged in `register()` as `mergeConfigFrom(__DIR__.'/Config/config.php', 'wanderer-sync')`.

### Database migration (consolidated, fresh-start)

```php
Schema::create('guarzo_wanderer_sync_instances', function (Blueprint $t) {
    $t->bigIncrements('id');
    $t->string('wanderer_url');
    $t->uuid('access_list_id');
    $t->string('access_list_token');
    $t->timestamps();
    $t->unique(['wanderer_url', 'access_list_id']);
});

Schema::create('guarzo_wanderer_sync_role_mappings', function (Blueprint $t) {
    $t->bigIncrements('id');
    $t->integer('role_id')->unsigned();
    $t->unsignedBigInteger('wanderer_instance_id');
    $t->timestamps();
    $t->unique(['role_id', 'wanderer_instance_id']);
    $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    $t->foreign('wanderer_instance_id')
      ->references('id')->on('guarzo_wanderer_sync_instances')
      ->cascadeOnDelete();
});
```

Differences from current schema:

- Table names re-prefixed with author (`guarzo_`) per SeAT dev guide.
- `_roles` → `_role_mappings` — clearer that it's a join table, not a role definition.
- Unique on `(wanderer_url, access_list_id)` excludes token, so rotating a token does not create
  a duplicate.
- Unique on `(role_id, wanderer_instance_id)` — silently drops duplicate mappings.
- `timestamps` on both tables.
- `cascadeOnDelete` on both FKs.

No `down()` migration chain from the old `seat_wanderer_access_sync_*` tables: fresh-start
decision means no deployed data to preserve.

### UI

`resources/views/list.blade.php` changes:

- Instance table: drop the token column entirely. Tokens are write-only. Show `aclName() ?? access_list_id`.
- Role-mapping table: show ACL name instead of UUID.
- Edit flow: to rotate a token, delete + recreate the instance. No in-place edit.
- Flash messages: neutral "already exists" (distinct from "added" success) when `Outcome::existed()`.

Translations (`resources/lang/en/settings.php`) add: `acl_name`, `token_write_only_notice`,
`already_exists`, `url_invalid`. No other languages in scope.

### Artisan command

```php
Artisan::command('wanderer-sync:run', function () {
    foreach (WandererAccessListInstance::all() as $instance) {
        UpdateWandererInstance::dispatch($instance);
    }
});
```

Schedule seeder updates `'command' => 'wanderer-sync:run'` accordingly.

## Testing

- **`tests/Unit/Driver/WandererClientTest.php`**: Guzzle `MockHandler`. Covers happy paths for
  each verb, 401 → `BadApiKeyException`, 404 on remove → `NotFoundException`, retry behavior
  on 503 (2 failures + success), URL validator rejection at construction.
- **`tests/Unit/Services/SyncServiceTest.php`**: `Mockery` doubles for `WandererClient` and
  `UserCharacterResolver`. Covers add-only, remove-only, mixed, empty, one-member-fails-but-rest-continue,
  instance-with-no-mappings short-circuits, `NotFoundException` on remove counts as success,
  `BadApiKeyException` aborts the run and bubbles to the caller.
- **`tests/Unit/Services/MappingServiceTest.php`**: minimal `orchestra/testbench` boot with
  in-memory SQLite so Eloquent unique-index assertions work. Covers duplicate mapping → `existed`,
  duplicate instance → `existed`, invalid URL → `error`.
- **`tests/Unit/Support/TokenSanitizerTest.php`**: null, short strings (< `visible_chars`),
  long strings.
- **`tests/Unit/Support/WandererUrlValidatorTest.php`**: http allowed, https allowed, ftp rejected,
  `localhost` rejected, `10.0.0.1` rejected, public domain allowed, IPv6 loopback rejected.

PHPStan level 5 on `src/` and `tests/`.

## CI (`.github/workflows/ci.yml`)

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ["8.1", "8.2", "8.3"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, dom, fileinfo, pdo, sqlite
          coverage: none
      - run: composer validate --strict
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: vendor/bin/phpunit
      - run: vendor/bin/phpstan analyse --level=5
```

## Composer changes

```json
{
  "name": "guarzo/seat-wanderer-sync",
  "description": "Sync SeAT roles to Wanderer ACLs",
  "type": "seat-plugin",
  "license": "GPL-2.0-or-later",
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
    "psr-4": { "Guarzo\\Seat\\WandererSync\\Tests\\": "tests/" }
  },
  "extra": {
    "laravel": {
      "providers": ["Guarzo\\Seat\\WandererSync\\WandererSyncServiceProvider"]
    }
  }
}
```

## Risks and rollback

- **Breaking schema/naming.** Mitigated by the fresh-start decision — no deployed data to
  preserve. If the user later wants to migrate data from an existing `recursivetree/...` install,
  that would be a separate, additive migration; not in scope here.
- **Guzzle retry middleware behavior change** could cause double-writes if retries fire after a
  successful write that returned a retryable status. Mitigation: only retry 500/502/503/504/429;
  do not retry POST/DELETE on connection-reset. Document in `WandererClient`.
- **ACL-name API call on every settings-page render** (unless cached). Cache TTL 10 min
  mitigates; fallback to UUID on error.

## Open questions for plan phase

- Exact Guzzle retry middleware: use community package (`caseyamcl/guzzle_retry_middleware`) or
  write a ~30-line custom middleware? Custom is preferred to avoid another dependency for
  behavior this simple. Plan can decide.
- Whether `WandererClient` should accept a pre-built `GuzzleHttp\Client` (dependency injection,
  better testability) vs. building it internally. Plan can decide; recommendation is
  constructor-accepts-optional-client-for-tests.

## Success criteria

- All existing functionality preserved (syncs SeAT roles to Wanderer ACL members hourly).
- No tokens appear in views or logs.
- Transient Wanderer outages (503, timeouts) automatically retry and either succeed or emit a
  single summarized error per sync run — not a Laravel queue failure.
- One character failing a Wanderer API call does not prevent other characters in the same run
  from syncing.
- `notes.txt` items resolved; file removed.
- CI green on PHP 8.1, 8.2, 8.3.
- Composer package publishes as `guarzo/seat-wanderer-sync` without Packagist collision.
