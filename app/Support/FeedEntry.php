<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * One entry from a channel's RSS feed.
 *
 * These fields are archived verbatim the first time a video is seen and are
 * never read again for a video already stored.
 */
class FeedEntry
{
    public function __construct(
        public string $videoId,
        public string $title,
        public ?string $description,
        public CarbonImmutable $publishedAt,
        public ?string $thumbnailUrl,
    ) {}
}
