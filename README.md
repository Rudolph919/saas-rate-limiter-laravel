# SaaS Rate Limiter (Laravel)

[![Tests](https://github.com/Rudolph919/saas-rate-limiter-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/Rudolph919/saas-rate-limiter-laravel/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Tiered, per-tenant rate-limiting middleware for a multi-tenant API — stops one noisy client from starving everyone else's quota.

A .NET/ASP.NET Core port of this same project lives at [saas-rate-limiter-aspnet](https://github.com/Rudolph919/saas-rate-limiter-aspnet).

## The problem

A single tenant's automated sync job was consuming roughly 40% of a shared API's capacity, driving up latency and causing timeouts for every other client. The fix isn't in the route handlers — it's a middleware layer that enforces per-tenant and per-endpoint limits **before** any application logic runs, so abusive traffic gets stopped at the door instead of after it's already done damage.

## How it works

Incoming `/api/*` requests pass through `RateLimitMiddleware`, which delegates to two services:

1. **`RateLimitResolver`** — reads `X-Org-Id`, maps the org to a tier, matches the HTTP method + path against `config/rate_limits.php`, and returns up to two limits: one per-client ceiling and one per-endpoint rule.
2. **`RateLimitCounter`** — an in-memory fixed-window store. Each limit has a key like `client:org_acme` or `endpoint:org_acme:create_item`, tracking `{ count, window_start }`.

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

- PHP 8.3+
- Composer

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
│   Providers/AppServiceProvider.php        # Counter singleton
│   Services/RateLimiting/
│       RateLimitResolver.php               # Config → limits
│       RateLimitCounter.php                # Fixed-window store
│       ResolvedLimit.php                   # DTO
│       RateLimitResolution.php             # DTO
│       RateLimitResult.php                 # DTO
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

**Algorithm:** fixed window, boundaries aligned to epoch (`intdiv(timestamp, windowSeconds) * windowSeconds`). Simplest correct implementation, at the cost of boundary bursts — a client can send a full quota at the end of one window and again at the start of the next. `RateLimitCounter` is a plain in-process array, which is safe here because the counter is a singleton within a single PHP process.

**Endpoint rule matching** is hand-rolled path-wildcard matching evaluated top-to-bottom (first match wins) — fine for a handful of routes, but in a larger app I'd move to route-name-based limits to avoid order-dependent config.

## AI collaboration

Built with AI assistance, with a few corrections along the way. The one worth calling out: the AI's first pass sent every request with a missing or unrecognized `X-Org-Id` into a single shared "unknown" bucket. That recreates the exact noisy-neighbor bug this middleware exists to prevent — all anonymous clients would fight over one counter. The fix: a missing header is a `401` before rate limiting even runs; an unrecognized org ID still gets its own counter key on the default tier.

## What I'd change for production

- **Real auth before rate limiting** — the middleware currently trusts whatever `X-Org-Id` a caller sends. In production, resolve the org server-side from an API key/OAuth/JWT credential, never from a client-supplied header.
- **Redis-backed counters** — the in-memory store is per-process: a restart wipes all counters, and with N server instances an org's effective limit becomes roughly N × configured, since each instance counts independently. Redis with TTL = `window_seconds` fixes both.
- **Sliding window (or token bucket)** instead of fixed window, to remove the boundary-burst edge case.
- **Memory bound on the counter map** — nothing evicts stale keys today; with enough orgs × endpoints this grows unbounded in a long-lived process. TTL eviction or an LRU cap would address it.
- **Metrics** — 429 rate by org, counter map size, p99 latency before/after the middleware.

## Tests

```bash
php artisan test
```

**36 tests** covering:

| Area | File |
|------|------|
| Resolver (org, tier, paths, exempt) | `tests/Unit/RateLimiting/RateLimitResolverTest.php` |
| Counter (windows, Retry-After, burst) | `tests/Unit/RateLimiting/RateLimitCounterTest.php` |
| Middleware (401, 429, tiers) | `tests/Feature/RateLimitMiddlewareTest.php` |
| Noisy-tenant demo scenario | `tests/Feature/RateLimitDemoTest.php` |

## License

[MIT](LICENSE)
