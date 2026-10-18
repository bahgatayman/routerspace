<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google Maps (Create Workspace location picker). A BROWSER key: it is sent
     * to the page, so restrict it in Google Cloud to your site's HTTP referrers
     * and to "Maps JavaScript API" + "Places API (New)" (+ "Geocoding API" for
     * "Use my current location"). Leave empty to hide the map picker; manual
     * address entry always works. map_id is optional.
     */
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID'),
    ],

];
