# SaaS Rate Limiter (Laravel)

[![Tests](https://github.com/Rudolph919/saas-rate-limiter-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/Rudolph919/saas-rate-limiter-laravel/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Tiered, per-tenant rate-limiting middleware for a multi-tenant API — stops one noisy client from starving everyone else's quota.

A .NET/ASP.NET Core port of this same project lives at [saas-rate-limiter-aspnet](https://github.com/Rudolph919/saas-rate-limiter-aspnet).

## The problem

A single tenant's automated sync job was consuming roughly 40% of a shared API's capacity, driving up latency and causing timeouts for every other client. The fix isn't in the route handlers — it's a middleware layer that enforces per-tenant and per-endpoint limits **before** any application logic runs, so abusive traffic gets stopped at the door instead of after it's already done damage.

## How it works

Incoming `/api/*` requests pass through `RateLimitMiddleware`, which delegates to two services:

1. **`RateLimitResolver`** — reads `X-Org-Id`, maps the org to a tier, matches the HTTP method + path against `config/rate_limits.php`, and returns up to two limits: one per-client ceiling and one per-endpoint rule.
2. **`RateLimitCounter`** — fixed-window accounting over a `CounterStore`. Each limit has a key like `client:org_acme` or `endpoint:org_acme:create_item`. Counters live in **System V shared memory**, which is what makes them survive from one request to the next under PHP's share-nothing model — see [Where counters live](#where-counters-live).

Both limits are checked on every non-exempt request, **client first, then endpoint** — the first failure returns `429` with which limit tripped and a `Retry-After` header. Missing `X-Org-Id` is a `401` (an identity problem, not a quota problem). Write endpoints are stricter than reads on the same path.

| Method | Path | Auth | Limit (default config) |
|--------|------|------|------------------------|
| GET | `/api/health` | None (exempt) | Skipped |
| GET | `/api/items` | `X-Org-Id` required | 80/min endpoint + tier ceiling |
| POST | `/api/items` | `X-Org-Id` required | 20/min endpoint + tier ceiling |
| DELETE | `/api/items/{id}` | `X-Org-Id` required | 10/min endpoint + tier ceiling |

**Sample orgs** (see `config/rate_limits.php`):

| `X-Org-Id` | Tier | Client ceiling |
|------------|------|----------------|
| `org_acme` | premium | 500 / 60s |
| `org_globex` | standard | 100 / 60s |
| `org_initech` | standard | 100 / 60s |
| *(any other id)* | standard (default) | 100 / 60s |

## Requirements

- PHP 8.4.1+
- Composer
- The `sysvshm` and `sysvsem` extensions (bundled with most PHP builds, including Herd and the
  official Docker images). Without them the app falls back to the array store and **enforces
  nothing** — see [Where counters live](#where-counters-live).

## Setup

```bash
composer install
cp .env.example .env   # already done if created via create-project
php artisan key:generate
php artisan serve
```

Server runs at `http://127.0.0.1:8000`.

## Manual demo (curl)

Start the server, then run these in separate terminal tabs.

### 1. Health — exempt, no org header

```bash
curl -s http://127.0.0.1:8000/api/health | jq
```

### 2. Missing org — 401

```bash
curl -s -w "\nHTTP %{http_code}\n" http://127.0.0.1:8000/api/items
```

### 3. Normal read — 200

```bash
curl -s -H "X-Org-Id: org_acme" http://127.0.0.1:8000/api/items | jq
```

### 4. Noisy tenant vs other tenants

`org_globex` hammers writes; `org_acme` reads still work.

```bash
# Hammer POST until 429 (default endpoint limit: 20/min)
for i in $(seq 1 22); do
  echo -n "POST $i: "
  curl -s -o /dev/null -w "%{http_code}\n" -X POST \
    -H "X-Org-Id: org_globex" \
    http://127.0.0.1:8000/api/items
done

# See full 429 body + Retry-After on the next POST
curl -i -X POST \
  -H "X-Org-Id: org_globex" \
  http://127.0.0.1:8000/api/items

# Other tenant unaffected
curl -s -H "X-Org-Id: org_acme" http://127.0.0.1:8000/api/items | jq
```

### 5. Automated demo script

```bash
chmod +x scripts/demo-rate-limits.sh
./scripts/demo-rate-limits.sh
# Or against another host:
./scripts/demo-rate-limits.sh http://127.0.0.1:8000
```

## Error responses

**401 — missing `X-Org-Id`:**

```json
{
  "error": "unauthorized",
  "detail": "X-Org-Id header is required"
}
```

**429 — rate limit exceeded:**

```json
{
  "error": "rate_limit_exceeded",
  "limit": "per_endpoint",
  "detail": "Organization org_globex exceeded 20 requests per 60 seconds on create_item endpoint",
  "retry_after_seconds": 42
}
```

Response includes a `Retry-After: 42` header.

## Architecture

```
saas-rate-limiter-laravel/
├── app/
│   Http/
│   │   Controllers/ApiController.php      # Sample API handlers
│   │   Middleware/RateLimitMiddleware.php  # Entry point
│   Providers/AppServiceProvider.php        # Store driver selection
│   Services/RateLimiting/
│       RateLimitResolver.php               # Config → limits
│       RateLimitCounter.php                # Fixed-window accounting
│       ResolvedLimit.php                   # DTO
│       RateLimitResolution.php             # DTO
│       RateLimitResult.php                 # DTO
│       Stores/
│           CounterStore.php                # Interface (Redis drops in here)
│           SharedMemoryStore.php           # SysV shared memory + semaphore
│           ArrayStore.php                  # Per-request; tests only
├── bootstrap/app.php                       # Middleware registration
├── config/rate_limits.php                  # All limits (no code changes)
└── routes/api.php                          # Sample routes
```

```
HTTP Request (/api/*)
    │
    ▼
RateLimitMiddleware
    │
    ├─ RateLimitResolver.resolve()
    │     ├─ exempt path?        → pass through
    │     ├─ missing X-Org-Id?   → 401
    │     └─ build limits[]      → per_client + per_endpoint
    │
    ├─ RateLimitCounter.attempt() for each limit (client first)
    │     └─ over limit?         → 429 + Retry-After
    │
    └─ pass to route handler
```

No rate-limiting logic appears in routes or controllers — all enforcement lives in the middleware, and every threshold is config-driven (`config/rate_limits.php`), so tuning a limit never touches application code.

**Algorithm:** fixed window, boundaries aligned to epoch (`intdiv(timestamp, windowSeconds) * windowSeconds`). Simplest correct implementation, at the cost of boundary bursts — a client can send a full quota at the end of one window and again at the start of the next, so the real worst case is 2× the configured limit over a short span.

**Endpoint rule matching** is hand-rolled path-wildcard matching evaluated top-to-bottom (first match wins) — fine for a handful of routes, but in a larger app I'd move to route-name-based limits to avoid order-dependent config.

## Where counters live

This is the part worth reading, because the first version of this project got it wrong.

Counters were originally a plain PHP array on a `RateLimitCounter` singleton bound into the
service container. Every test passed. Over real HTTP it enforced **nothing** — 30 consecutive
requests against a 10/minute limit all returned `200`.

PHP is share-nothing: Laravel rebuilds the service container on every request, so a
"singleton" is really scoped to one request. The array was empty each time and every request
counted to 1. The test suite could not see it, because PHPUnit runs a whole test method in a
single process against a single booted container — so there the array genuinely did survive
between calls. **The tests and the production path had different state lifetimes, and only the
lifetime that worked was ever tested.**

The fix is `CounterStore`, an interface with one meaningful operation — "increment this key in
this window and tell me the new count", atomically:

| Driver | State lives in | Use it for |
|---|---|---|
| `shared_memory` (default) | System V shared memory segment, guarded by a semaphore | Real deployments. Kernel-owned, so it outlives both the request and the PHP worker, and is shared by every FPM worker on the host. |
| `array` | A PHP array on the object | Tests, or a persistent-worker runtime (Octane, RoadRunner, FrankenPHP) where the container really does outlive the request. |

Select with `RATE_LIMIT_STORE` or `rate_limits.store.driver`; `auto` (the default) picks
`shared_memory` when the extensions are present. No Redis, no database, no external cache — the
in-memory constraint still holds.

**What this buys, and what it does not:**

- Counters are shared across all workers **on one host**. Behind a load balancer with N hosts,
  an org's effective limit is still roughly N × configured. Only a shared store fixes that.
- Counters now **survive an application restart**, since the segment belongs to the kernel. That
  is a deliberate change: abuse state should not reset because someone deployed.
- Expired `(key, window)` entries are pruned on write, so the map stays bounded. If the segment
  fills anyway, the store drops all counters and continues rather than failing requests —
  bounded memory, at the cost of one forgiving window.
- Serialising the whole map per request is the main scaling ceiling, and the clearest argument
  for moving to Redis, where `INCR` touches exactly one key.

**How this is prevented from regressing:** an in-process test structurally cannot catch a
state-lifetime bug — it shares a process with the code under test. Two things guard it now.
`SharedMemoryStoreTest` throws the store away and builds a new one between increments, which is
what a second HTTP request does. And `scripts/demo-rate-limits.sh` makes real HTTP requests and
**exits non-zero** if the limit never trips — the old version printed 22 success lines and
exited 0 while the feature was dead.

## AI collaboration

Built with AI assistance, with a few corrections along the way.

**Caught during review:** the AI's first pass sent every request with a missing or unrecognized `X-Org-Id` into a single shared "unknown" bucket. That recreates the exact noisy-neighbor bug this middleware exists to prevent — all anonymous clients would fight over one counter. The fix: a missing header is a `401` before rate limiting even runs; an unrecognized org ID still gets its own counter key on the default tier.

**Missed during review, caught by running it:** the in-process array counter described in [Where counters live](#where-counters-live). Both the AI and I reasoned about it as "a singleton, so it persists," and the green test suite agreed. It took actually hammering a running server to find out otherwise. The lesson generalises past this bug — reviewing generated code by reading it will not catch assumptions that are only wrong at runtime.

## What I'd change for production

- **Real auth before rate limiting** — the middleware currently trusts whatever `X-Org-Id` a caller sends. In production, resolve the org server-side from an API key/OAuth/JWT credential, never from a client-supplied header.
- **Redis-backed counters** — shared memory is per host, so with N hosts behind a load balancer an org's effective limit is still roughly N × configured. A `RedisStore` implementing `CounterStore` (`INCR` + `EXPIRE`, or a small Lua script to make the pair atomic) fixes that, and nothing above the interface changes. It also removes the whole-map serialisation cost.
- **Sliding window (or token bucket)** instead of fixed window, to remove the boundary-burst edge case.
- **Metrics** — 429 rate by org, counter map size, p99 latency before/after the middleware.

## Tests

```bash
php artisan test
```

**42 tests** covering:

| Area | File |
|------|------|
| Resolver (org, tier, paths, exempt) | `tests/Unit/RateLimiting/RateLimitResolverTest.php` |
| Counter (windows, Retry-After, burst) | `tests/Unit/RateLimiting/RateLimitCounterTest.php` |
| Store lifetime, pruning, isolation | `tests/Unit/RateLimiting/SharedMemoryStoreTest.php` |
| Middleware (401, 429, tiers) | `tests/Feature/RateLimitMiddlewareTest.php` |
| Noisy-tenant demo scenario | `tests/Feature/RateLimitDemoTest.php` |

The suite forces the `array` driver (see `phpunit.xml`) so runs stay deterministic and never
leave counters behind. That means **`php artisan test` alone cannot prove the limiter works** —
it runs in one process, where even the broken array store looks correct. Run the demo script
against a live server for that:

```bash
php artisan serve &
./scripts/demo-rate-limits.sh    # exits non-zero if limits are not enforced
```

## License

[MIT](LICENSE)
