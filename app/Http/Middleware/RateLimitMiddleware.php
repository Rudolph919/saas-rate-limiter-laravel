<?php

namespace App\Http\Middleware;

use App\Services\RateLimiting\RateLimitCounter;
use App\Services\RateLimiting\RateLimitResolver;
use App\Services\RateLimiting\RateLimitResult;
use App\Services\RateLimiting\RateLimitResolution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitResolver $resolver,
        private readonly RateLimitCounter $counter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolution = $this->resolver->resolve($request);

        if ($resolution->exempt) {
            return $next($request);
        }

        if ($resolution->missingOrg) {
            return $this->missingOrgResponse();
        }

        foreach ($resolution->limits as $limit) {
            $result = $this->counter->attempt($limit);

            if (! $result->allowed) {
                return $this->rateLimitResponse($result, $resolution);
            }
        }

        return $next($request);
    }

    private function missingOrgResponse(): Response
    {
        $header = config('rate_limits.org_header', 'X-Org-Id');

        return response()->json([
            'error' => 'unauthorized',
            'detail' => "{$header} header is required",
        ], Response::HTTP_UNAUTHORIZED);
    }

    private function rateLimitResponse(
        RateLimitResult $result,
        RateLimitResolution $resolution,
    ): Response {
        $windowSeconds = (int) config('rate_limits.window_seconds', 60);

        return response()->json([
            'error' => 'rate_limit_exceeded',
            'limit' => $result->type,
            'detail' => $this->buildDetailMessage($result, $resolution, $windowSeconds),
            'retry_after_seconds' => $result->retryAfterSeconds,
        ], Response::HTTP_TOO_MANY_REQUESTS)->withHeaders([
            'Retry-After' => (string) $result->retryAfterSeconds,
        ]);
    }

    private function buildDetailMessage(
        RateLimitResult $result,
        RateLimitResolution $resolution,
        int $windowSeconds,
    ): string {
        $orgId = $resolution->orgId;

        if ($result->type === 'per_client') {
            return "Organization {$orgId} exceeded {$result->maxRequests} requests per {$windowSeconds} seconds ({$result->name} tier)";
        }

        return "Organization {$orgId} exceeded {$result->maxRequests} requests per {$windowSeconds} seconds on {$result->name} endpoint";
    }
}
