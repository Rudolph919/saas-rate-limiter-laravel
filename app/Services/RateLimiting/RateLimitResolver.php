<?php

namespace App\Services\RateLimiting;

use Illuminate\Http\Request;

class RateLimitResolver
{
    public function resolve(Request $request): RateLimitResolution
    {
        $path = $this->normalizePath($request->path());

        if ($this->isExempt($path)) {
            $orgId = $this->extractOrgId($request);

            return new RateLimitResolution(
                exempt: true,
                missingOrg: false,
                orgId: $orgId,
                tier: $orgId !== null ? $this->resolveTier($orgId) : null,
                limits: [],
            );
        }

        $orgId = $this->extractOrgId($request);

        if ($orgId === null) {
            return new RateLimitResolution(
                exempt: false,
                missingOrg: true,
                orgId: null,
                tier: null,
                limits: [],
            );
        }

        $tier = $this->resolveTier($orgId);
        $windowSeconds = (int) config('rate_limits.window_seconds', 60);

        $limits = [
            $this->resolveClientLimit($orgId, $tier, $windowSeconds),
            $this->resolveEndpointLimit($request, $orgId, $path, $windowSeconds),
        ];

        return new RateLimitResolution(
            exempt: false,
            missingOrg: false,
            orgId: $orgId,
            tier: $tier,
            limits: $limits,
        );
    }

    public function extractOrgId(Request $request): ?string
    {
        $header = config('rate_limits.org_header', 'X-Org-Id');
        $orgId = $request->header($header);

        if ($orgId === null || $orgId === '') {
            return null;
        }

        return $orgId;
    }

    public function resolveTier(string $orgId): string
    {
        $organizations = config('rate_limits.organizations', []);
        $tier = $organizations[$orgId] ?? config('rate_limits.default_tier', 'standard');

        $tiers = config('rate_limits.tiers', []);

        if (! array_key_exists($tier, $tiers)) {
            return config('rate_limits.default_tier', 'standard');
        }

        return $tier;
    }

    public function pathMatches(string $path, string $pattern): bool
    {
        $path = $this->normalizePath($path);
        $pattern = $this->normalizePath($pattern);

        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');

            if ($prefix === '') {
                return true;
            }

            return $path === $prefix || str_starts_with($path, $prefix.'/');
        }

        return $path === $pattern;
    }

    private function isExempt(string $path): bool
    {
        foreach (config('rate_limits.exempt', []) as $exemptPath) {
            if ($this->pathMatches($path, $exemptPath)) {
                return true;
            }
        }

        return false;
    }

    private function resolveClientLimit(string $orgId, string $tier, int $windowSeconds): ResolvedLimit
    {
        $tierConfig = config("rate_limits.tiers.{$tier}");
        $maxRequests = (int) ($tierConfig['max_requests'] ?? 100);

        return new ResolvedLimit(
            key: "client:{$orgId}",
            type: 'per_client',
            name: $tier,
            maxRequests: $maxRequests,
            windowSeconds: $windowSeconds,
        );
    }

    private function resolveEndpointLimit(
        Request $request,
        string $orgId,
        string $path,
        int $windowSeconds,
    ): ResolvedLimit {
        $method = strtoupper($request->method());
        $rule = $this->findEndpointRule($method, $path);

        if ($rule === null) {
            $fallback = config('rate_limits.default_endpoint_limit', []);

            return new ResolvedLimit(
                key: "endpoint:{$orgId}:default",
                type: 'per_endpoint',
                name: $fallback['name'] ?? 'default',
                maxRequests: (int) ($fallback['max_requests'] ?? 30),
                windowSeconds: $windowSeconds,
            );
        }

        return new ResolvedLimit(
            key: "endpoint:{$orgId}:{$rule['name']}",
            type: 'per_endpoint',
            name: $rule['name'],
            maxRequests: (int) $rule['max_requests'],
            windowSeconds: $windowSeconds,
        );
    }

    /**
     * @return array{name: string, max_requests: int}|null
     */
    private function findEndpointRule(string $method, string $path): ?array
    {
        foreach (config('rate_limits.endpoint_limits', []) as $rule) {
            if (! in_array($method, $rule['methods'] ?? [], true)) {
                continue;
            }

            if ($this->pathMatches($path, $rule['path'] ?? '')) {
                return $rule;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return trim($path, '/');
    }
}
