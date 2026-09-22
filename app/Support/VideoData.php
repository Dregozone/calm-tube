<?php

namespace App\Support;

use App\Enums\LiveStatus;
use Carbon\CarbonImmutable;

/**
 * The details videos.list adds to a video already discovered through the feed.
 *
 * Carries no title or description: those are archived at first ingest and must
 * never be overwritten by a later lookup.
 */
class VideoData
{
    public function __construct(
        public string $videoId,
        public ?int $durationSeconds = null,
        public LiveStatus $liveStatus = LiveStatus::None,
        public ?CarbonImmutable $scheduledStartAt = null,
    ) {}
}
