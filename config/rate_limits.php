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
