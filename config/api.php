<?php

/*
|--------------------------------------------------------------------------
| Customer API (/api/v1)
|--------------------------------------------------------------------------
|
| Settings for the public, read-only API consumed by the Link Space website
| and mobile app. Nothing here is a secret.
|
*/

return [

    // Requests per minute per client IP for the public endpoints.
    'public_rate_limit' => (int) env('API_PUBLIC_RATE_LIMIT', 60),

    // Workspace directory pagination.
    'per_page_default' => 12,
    'per_page_max' => 50,

];
