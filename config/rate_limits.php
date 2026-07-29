<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organization Identity
    |--------------------------------------------------------------------------
    |
    | B2B clients are identified by the X-Org-Id request header. Requests to
    | non-exempt routes without this header are rejected (401) by middleware.
    | Unknown org IDs (not in organizations) receive default_tier but keep
    | their own counter key — they do not share a global anonymous bucket.
    |
    | PoC note: X-Org-Id is trusted without authentication. See PRODUCTION_NOTES.md.
    |
    */
    'org_header' => 'X-Org-Id',

    'default_tier' => 'standard',

    /*
    |--------------------------------------------------------------------------
    | Counter Store
    |--------------------------------------------------------------------------
    |
    | Where fixed-window counters live. This is the setting that decides whether
    | the limiter works at all.
    |
    | - shared_memory: System V shared memory + semaphore. Counters survive across
    |                  requests and are shared by every PHP-FPM worker on the host.
    |                  This is the only option that enforces limits over real HTTP
    |                  under PHP's share-nothing request model.
    | - array:         Plain PHP array, scoped to one request. Enforces NOTHING in a
    |                  normal deployment — the container is rebuilt per request, so the
    |                  array is always empty. Correct only for tests, or for a
    |                  persistent-worker runtime such as Laravel Octane.
    | - auto:          shared_memory when the sysvshm/sysvsem extensions are loaded,
    |                  array otherwise.
    |
    | Tests force 'array' via phpunit.xml so they stay deterministic and do not
    | leak counters between runs.
    |
    */
    'store' => [
        'driver' => env('RATE_LIMIT_STORE', 'auto'),

        'shared_memory' => [
            // Single character; combined with the app path by ftok() to derive the
            // System V key. Change it to give a second app on the same host its own
            // segment.
            'project_id' => env('RATE_LIMIT_SHM_PROJECT_ID', 'r'),

            // Fixed segment size. Holds roughly a few thousand (org, limit) pairs;
            // when full the store drops all counters rather than failing requests.
            'segment_bytes' => (int) env('RATE_LIMIT_SHM_BYTES', 1048576),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time Window
    |--------------------------------------------------------------------------
    |
    | All limits use max_requests per window_seconds. Changing window_seconds
    | scales the window without renaming config keys.
    |
    */
    'window_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Per-Client Tiers
    |--------------------------------------------------------------------------
    |
    | Org-wide ceiling applied to every request for that organization, regardless
    | of endpoint. Premium tenants get a higher total budget.
    |
    */
    'tiers' => [
        'standard' => [
            'label' => 'Standard',
            'max_requests' => 100,
        ],
        'premium' => [
            'label' => 'Premium',
            'max_requests' => 500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Organization → Tier Mapping
    |--------------------------------------------------------------------------
    |
    | Assign each client org to a tier. Add or change entries here without
    | touching application code.
    |
    */
    'organizations' => [
        'org_acme' => 'premium',
        'org_globex' => 'standard',
        'org_initech' => 'standard',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exempt Paths
    |--------------------------------------------------------------------------
    |
    | Routes that skip rate limiting entirely (e.g. health checks for load
    | balancers). Paths are matched without a leading slash.
    |
    */
    'exempt' => [
        'api/health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Endpoint Limits
    |--------------------------------------------------------------------------
    |
    | Stricter limits on write operations than reads. Rules are evaluated in
    | order; the first matching rule wins.
    |
    | - methods: HTTP verbs this rule applies to
    | - path: route path pattern (* = wildcard suffix)
    | - max_requests: max requests allowed within window_seconds
    | - name: identifier returned in 429 responses (per_endpoint limit hit)
    |
    */
    'endpoint_limits' => [
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
            'max_requests' => 20,
        ],
        [
            'name' => 'update_item',
            'methods' => ['PUT', 'PATCH'],
            'path' => 'api/items*',
            'max_requests' => 20,
        ],
        [
            'name' => 'delete_item',
            'methods' => ['DELETE'],
            'path' => 'api/items*',
            'max_requests' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Endpoint Limit (fallback)
    |--------------------------------------------------------------------------
    |
    | Applied when no endpoint_limits rule matches. Keeps unlisted routes bounded.
    |
    */
    'default_endpoint_limit' => [
        'name' => 'default',
        'max_requests' => 30,
    ],

];
