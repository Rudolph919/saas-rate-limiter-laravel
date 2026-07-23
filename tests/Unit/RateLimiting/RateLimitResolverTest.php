<?php

namespace Tests\Unit\RateLimiting;

use App\Services\RateLimiting\RateLimitResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RateLimitResolverTest extends TestCase
{
    private RateLimitResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new RateLimitResolver;
    }

    public function test_exempt_path_skips_limits_without_org_header(): void
    {
        $request = Request::create('/api/health', 'GET');

        $resolution = $this->resolver->resolve($request);

        $this->assertTrue($resolution->exempt);
        $this->assertFalse($resolution->missingOrg);
        $this->assertNull($resolution->orgId);
        $this->assertSame([], $resolution->limits);
    }

    public function test_exempt_path_still_resolves_org_when_present(): void
    {
        $request = Request::create('/api/health', 'GET');
        $request->headers->set('X-Org-Id', 'org_acme');

        $resolution = $this->resolver->resolve($request);

        $this->assertTrue($resolution->exempt);
        $this->assertSame('org_acme', $resolution->orgId);
        $this->assertSame('premium', $resolution->tier);
    }

    public function test_premium_org_gets_higher_client_limit(): void
    {
        $request = Request::create('/api/items', 'GET');
        $request->headers->set('X-Org-Id', 'org_acme');

        $resolution = $this->resolver->resolve($request);

        $this->assertFalse($resolution->exempt);
        $this->assertSame('org_acme', $resolution->orgId);
        $this->assertSame('premium', $resolution->tier);
        $this->assertSame(500, $resolution->limits[0]->maxRequests);
        $this->assertSame('per_client', $resolution->limits[0]->type);
        $this->assertSame('client:org_acme', $resolution->limits[0]->key);
    }

    public function test_unknown_org_uses_default_tier_with_own_counter_key(): void
    {
        $request = Request::create('/api/items', 'GET');
        $request->headers->set('X-Org-Id', 'org_unknown');

        $resolution = $this->resolver->resolve($request);

        $this->assertFalse($resolution->missingOrg);
        $this->assertSame('org_unknown', $resolution->orgId);
        $this->assertSame('standard', $resolution->tier);
        $this->assertSame(100, $resolution->limits[0]->maxRequests);
        $this->assertSame('client:org_unknown', $resolution->limits[0]->key);
    }

    public function test_missing_org_header_flags_rejection(): void
    {
        $request = Request::create('/api/items', 'GET');

        $resolution = $this->resolver->resolve($request);

        $this->assertFalse($resolution->exempt);
        $this->assertTrue($resolution->missingOrg);
        $this->assertNull($resolution->orgId);
        $this->assertNull($resolution->tier);
        $this->assertSame([], $resolution->limits);
    }

    public function test_get_items_uses_read_endpoint_limit(): void
    {
        $request = Request::create('/api/items', 'GET');
        $request->headers->set('X-Org-Id', 'org_globex');

        $resolution = $this->resolver->resolve($request);
        $endpointLimit = $resolution->limits[1];

        $this->assertSame('per_endpoint', $endpointLimit->type);
        $this->assertSame('read_items', $endpointLimit->name);
        $this->assertSame(80, $endpointLimit->maxRequests);
        $this->assertSame('endpoint:org_globex:read_items', $endpointLimit->key);
    }

    public function test_post_items_uses_stricter_write_limit(): void
    {
        $request = Request::create('/api/items', 'POST');
        $request->headers->set('X-Org-Id', 'org_globex');

        $resolution = $this->resolver->resolve($request);

        $this->assertSame('create_item', $resolution->limits[1]->name);
        $this->assertSame(20, $resolution->limits[1]->maxRequests);
    }

    public function test_delete_item_with_id_matches_delete_rule(): void
    {
        $request = Request::create('/api/items/42', 'DELETE');
        $request->headers->set('X-Org-Id', 'org_globex');

        $resolution = $this->resolver->resolve($request);

        $this->assertSame('delete_item', $resolution->limits[1]->name);
        $this->assertSame(10, $resolution->limits[1]->maxRequests);
    }

    public function test_unlisted_route_uses_default_endpoint_limit(): void
    {
        $request = Request::create('/api/reports', 'GET');
        $request->headers->set('X-Org-Id', 'org_globex');

        $resolution = $this->resolver->resolve($request);

        $this->assertSame('default', $resolution->limits[1]->name);
        $this->assertSame(30, $resolution->limits[1]->maxRequests);
        $this->assertSame('endpoint:org_globex:default', $resolution->limits[1]->key);
    }

    #[DataProvider('pathMatchProvider')]
    public function test_path_matching(string $path, string $pattern, bool $expected): void
    {
        $this->assertSame($expected, $this->resolver->pathMatches($path, $pattern));
    }

    public static function pathMatchProvider(): array
    {
        return [
            'exact match' => ['api/items', 'api/items', true],
            'wildcard item id' => ['api/items/42', 'api/items*', true],
            'wildcard base path' => ['api/items', 'api/items*', true],
            'no false positive prefix' => ['api/items-archive', 'api/items*', false],
            'different route' => ['api/users', 'api/items*', false],
            'health exact' => ['api/health', 'api/health', true],
        ];
    }
}
