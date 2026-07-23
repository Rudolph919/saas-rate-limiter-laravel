<?php

namespace Tests\Feature;

use App\Services\RateLimiting\RateLimitCounter;
use Tests\TestCase;

class RateLimitDemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(RateLimitCounter::class)->reset();
    }

    public function test_noisy_tenant_post_limit_does_not_block_other_tenant_reads(): void
    {
        config([
            'rate_limits.tiers.standard.max_requests' => 500,
            'rate_limits.endpoint_limits' => [
                [
                    'name' => 'create_item',
                    'methods' => ['POST'],
                    'path' => 'api/items*',
                    'max_requests' => 3,
                ],
                [
                    'name' => 'read_items',
                    'methods' => ['GET', 'HEAD'],
                    'path' => 'api/items*',
                    'max_requests' => 80,
                ],
            ],
        ]);

        $noisyTenant = ['X-Org-Id' => 'org_globex'];
        $otherTenant = ['X-Org-Id' => 'org_acme'];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/items', [], $noisyTenant)->assertCreated();
        }

        $this->postJson('/api/items', [], $noisyTenant)
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limit_exceeded',
                'limit' => 'per_endpoint',
            ]);

        $this->getJson('/api/items', $otherTenant)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Widget');
    }
}
