<?php

namespace App\Support;

/**
 * A fetched feed, or the fact that it has not changed since last time.
 */
class FeedResponse
{
    /**
     * @param  list<FeedEntry>  $entries
     */
    public function __construct(
        public array $entries = [],
        public ?string $channelTitle = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public bool $notModified = false,
    ) {}

    public static function notModified(?string $etag, ?string $lastModified): self
    {
        return new self(
            etag: $etag,
            lastModified: $lastModified,
            notModified: true,
        );
    }
}
