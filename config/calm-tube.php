<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | YouTube Data API Key
    |--------------------------------------------------------------------------
    |
    | Used only to enrich videos discovered through each channel's public RSS
    | feed, and to resolve a pasted handle to a channel. The app must boot and
    | run without it: durations are simply unavailable until one is set.
    |
    */

    'api_key' => env('YOUTUBE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    |
    | Applied to every outbound request. Refreshes run synchronously, so these
    | values are also how long a manual refresh can make you wait.
    |
    */

    'refresh' => [
        'timeout' => 10,
        'retries' => 2,
        'retry_delay' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shorts Detection
    |--------------------------------------------------------------------------
    |
    | Neither the RSS feed nor the Data API says whether a video is a Short, so
    | detection is a duration gate plus a probe of youtube.com/shorts/{id}.
    | Anything longer than probe_max_seconds cannot be a Short and is never
    | probed. When the probe cannot answer, fallback_max_seconds decides, which
    | deliberately keeps genuinely short videos of 1 to 3 minutes.
    |
    */

    'shorts' => [
        'probe' => true,
        'probe_max_seconds' => 180,
        'fallback_max_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Archived Images
    |--------------------------------------------------------------------------
    |
    | Thumbnails are downloaded once and served locally, so that a thumbnail
    | swapped on YouTube later cannot change what you see. Only 16:9 variants
    | are used; the 4:3 ones carry letterbox bars.
    |
    */

    'images' => [
        'preferred' => ['maxresdefault', 'mqdefault'],
        'disk' => 'local',
        'path' => 'calm-tube',
        'minimum_bytes' => 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed
    |--------------------------------------------------------------------------
    |
    | Small enough that a page feels finite, large enough not to paginate
    | constantly. There is no infinite scroll.
    |
    */

    'feed' => [
        'per_page' => 24,
    ],

];
