<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Only the versioned customer API is cross-origin. Origins are an explicit
| allow-list from CORS_ALLOWED_ORIGINS (comma-separated, exact scheme+host,
| e.g. "https://link-space.net,https://www.link-space.net"); empty means no
| browser origin is allowed. No wildcard, and no credentials: the public API
| is read-only and cookie-less.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn ($origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
