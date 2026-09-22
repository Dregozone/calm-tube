<?php

namespace App\Enums;

/**
 * Whether a video is an ordinary upload, or a stream that has not finished.
 *
 * Only None appears in the feed. Live and Upcoming rows are stored but hidden,
 * and become visible once a later enrichment finds the stream has ended.
 */
enum LiveStatus: string
{
    case None = 'none';
    case Live = 'live';
    case Upcoming = 'upcoming';
}
