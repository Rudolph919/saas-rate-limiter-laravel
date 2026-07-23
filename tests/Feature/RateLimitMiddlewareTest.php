<?php

namespace Tests\Feature;

use App\Services\RateLimiting\RateLimitCounter;
use Tests\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(RateLimitCounter::class)->reset();
    }

    public function test_missing_org_header_returns_401(): void
    {
        $response = $this->getJson('/api/items');

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'unauthorized',
                'detail' => 'X-Org-Id header is required',
            ]);
    }

    public function test_exempt_health_route_works_without_org_header(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_request_under_limit_succeeds(): void
    {
        $response = $this->getJson('/api/items', [
            'X-Org-Id' => 'org_globex',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Widget');
    }

    public function test_post_endpoint_limit_does_not_block_get_for_same_org(): void
    {
        config([
            'rate_limits.tiers.standard.max_requests' => 500,
            'rate_limits.endpoint_limits' => [
                [
                    'name' => 'read_items',
                    'methods' => ['GET', 'HEAD'],
                    'path' => 'api/items*',
                    'max_requests' => 80,
                ],
                [
                    'name' => 'create_item',
                    'methods' => ['POST'],
                    'path' => 'api/items*',
                    'max_requests' => 2,
                ],
            ],
        ]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->postJson('/api/items', [], $headers)->assertCreated();
        $this->postJson('/api/items', [], $headers)->assertCreated();

        $this->postJson('/api/items', [], $headers)
            ->assertStatus(429)
            ->assertJson(['limit' => 'per_endpoint']);

        $this->getJson('/api/items', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', 1);
    }

    public function test_delete_endpoint_limit_returns_429(): void
    {
        config([
            'rate_limits.tiers.standard.max_requests' => 500,
            'rate_limits.endpoint_limits' => [
                [
                    'name' => 'delete_item',
                    'methods' => ['DELETE'],
                    'path' => 'api/items*',
                    'max_requests' => 2,
                ],
            ],
        ]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->deleteJson('/api/items/1', [], $headers)->assertOk();
        $this->deleteJson('/api/items/2', [], $headers)->assertOk();

        $response = $this->deleteJson('/api/items/3', [], $headers);

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limit_exceeded',
                'limit' => 'per_endpoint',
            ])
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('delete_item', $response->json('detail'));
    }

    public function test_retry_after_header_matches_response_body(): void
    {
        config(['rate_limits.tiers.standard.max_requests' => 1]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->getJson('/api/items', $headers)->assertOk();

        $response = $this->getJson('/api/items', $headers);

        $response->assertStatus(429);

        $this->assertSame(
            (string) $response->json('retry_after_seconds'),
            $response->headers->get('Retry-After'),
        );
    }

    public function test_per_client_429_detail_names_tier(): void
    {
        config(['rate_limits.tiers.standard.max_requests' => 1]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->getJson('/api/items', $headers)->assertOk();

        $response = $this->getJson('/api/items', $headers);

        $response->assertStatus(429)
            ->assertJson(['limit' => 'per_client']);

        $this->assertStringContainsString('org_globex', $response->json('detail'));
        $this->assertStringContainsString('standard', $response->json('detail'));
    }

    public function test_per_client_limit_returns_429(): void
    {
        config(['rate_limits.tiers.standard.max_requests' => 2]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->getJson('/api/items', $headers)->assertOk();
        $this->getJson('/api/items', $headers)->assertOk();

        $response = $this->getJson('/api/items', $headers);

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limit_exceeded',
                'limit' => 'per_client',
            ])
            ->assertJsonStructure(['detail', 'retry_after_seconds'])
            ->assertHeader('Retry-After');
    }

    public function test_per_endpoint_limit_returns_429_on_post(): void
    {
        config([
            'rate_limits.tiers.standard.max_requests' => 100,
            'rate_limits.endpoint_limits' => [
                [
                    'name' => 'create_item',
                    'methods' => ['POST'],
                    'path' => 'api/items*',
                    'max_requests' => 2,
                ],
            ],
        ]);

        $headers = ['X-Org-Id' => 'org_globex'];

        $this->postJson('/api/items', [], $headers)->assertCreated();
        $this->postJson('/api/items', [], $headers)->assertCreated();

        $response = $this->postJson('/api/items', [], $headers);

        $response->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limit_exceeded',
                'limit' => 'per_endpoint',
            ])
            ->assertHeader('Retry-After');

        $this->assertStringContainsString('create_item', $response->json('detail'));
    }

    public function test_premium_org_has_higher_client_ceiling(): void
    {
        config(['rate_limits.tiers.standard.max_requests' => 2]);

        $standardHeaders = ['X-Org-Id' => 'org_globex'];
        $premiumHeaders = ['X-Org-Id' => 'org_acme'];

        $this->getJson('/api/items', $standardHeaders)->assertOk();
        $this->getJson('/api/items', $standardHeaders)->assertOk();
        $this->getJson('/api/items', $standardHeaders)->assertStatus(429);

        $this->getJson('/api/items', $premiumHeaders)->assertOk();
    }

    public function test_unknown_org_gets_default_tier_not_shared_unknown_bucket(): void
    {
        config(['rate_limits.tiers.standard.max_requests' => 2]);

        $orgA = ['X-Org-Id' => 'org_new_customer_a'];
        $orgB = ['X-Org-Id' => 'org_new_customer_b'];

        $this->getJson('/api/items', $orgA)->assertOk();
        $this->getJson('/api/items', $orgA)->assertOk();
        $this->getJson('/api/items', $orgA)->assertStatus(429);

        $this->getJson('/api/items', $orgB)->assertOk();
    }
}
