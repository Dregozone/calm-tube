<?php

namespace App\Support;

/**
 * A video met for the first time outside any channel you follow, with the
 * channel it belongs to, so both can be archived.
 */
class DiscoveredVideo
{
    public function __construct(
        public FeedEntry $entry,
        public string $channelId,
        public string $channelTitle,
    ) {}
}
