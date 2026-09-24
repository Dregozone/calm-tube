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
        // YouTube's RSS feed is free and unmetered, but it has been answering
        // 404 intermittently since late 2025, so a refresh falls back to the
        // uploads playlist when it does. Turn this off to skip the feed
        // entirely if it stays down, at one quota unit per channel per refresh.
        'try_feed' => env('CALM_TUBE_TRY_FEED', true),
        'timeout' => 10,
        'retries' => 2,
        'retry_delay' => 1000,

        // A web request gets 30 seconds from PHP, which a run over every
        // channel can outlast. Each channel restarts the clock with this
        // long, so a big haul of Shorts to probe cannot cut a run short.
        // The command line has no limit and is left without one.
        'seconds_per_channel' => 120,

        // Refresh history is operational log data, not part of the archive,
        // and it is the only thing this app ever deletes on a schedule.
        'keep_runs_for_days' => 30,

        // Opening a feed nobody has refreshed for this long refreshes it,
        // after the page has rendered, so the app stays current without a
        // scheduler running in the background. 0 turns it off.
        'auto_after_hours' => env('CALM_TUBE_AUTO_REFRESH_HOURS', 6),
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
    | swapped on YouTube later cannot change what you see.
    |
    | The URL is whatever YouTube returned. Google's documentation asks
    | applications to use thumbnail URLs exactly as given rather than
    | substituting domains or guessing at variants, and the real feeds bear
    | that out: they return sharded hosts like i1.ytimg.com that no pattern
    | would have predicted. Cropping to 16:9 is the UI's job.
    |
    */

    'images' => [
        'disk' => 'local',
        'path' => 'calm-tube',
        'minimum_bytes' => 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Player
    |--------------------------------------------------------------------------
    |
    | Playback speed is remembered per channel, because it is a property of the
    | speaker rather than of any one video. The rates below are the ones the
    | YouTube IFrame API accepts for every video; anything else is refused
    | rather than silently ignored by the player.
    |
    | countdown_seconds is how long the end-of-video modal waits before
    | returning you to your list. Leaving is automatic; arriving anywhere new
    | never is.
    |
    */

    'player' => [
        'playback_rates' => [1.0, 1.25, 1.5, 1.75, 2.0],
        'countdown_seconds' => 3,

        // You clicked the video, so it plays. This is not the autoplay the
        // app exists to avoid: that one picks the next video for you, and
        // nothing here ever will. A part-watched video never autoplays,
        // because it has a question to ask you first.
        'autoplay' => env('CALM_TUBE_AUTOPLAY', true),

        // YouTube draws clickable end cards over the last seconds of a video,
        // inside the iframe where no embed parameter can remove them. For that
        // stretch a transparent layer covers the picture and swallows the
        // clicks; the control bar stays exposed, and clicking the layer
        // pauses, which is what clicking a video is for. Set to 0 to allow
        // end cards through.
        'end_card_mask_seconds' => 20,

        // How far the left and right arrow keys jump on the watch page.
        'seek_seconds' => 10,
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
        'per_page' => 18,

        // A fresh session opens on what you have not seen rather than on the
        // whole archive. Whichever you last chose is remembered after that.
        'default_filter' => 'unwatched',
    ],

];
