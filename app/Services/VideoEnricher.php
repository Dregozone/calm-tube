<?php

namespace App\Services;

use App\Enums\LiveStatus;
use App\Models\Video;
use App\Services\YouTube\DataApiClient;
use App\Services\YouTube\ShortsDetector;
use App\Support\VideoData;
use Illuminate\Support\Collection;

/**
 * Fills in what the RSS feed cannot say: duration, live status, whether the
 * video still exists, and whether it is a Short.
 *
 * Never touches the title, description or archived thumbnail. Those are
 * written once at first ingest and are not this class's business.
 */
class VideoEnricher
{
    public function __construct(
        private readonly DataApiClient $api,
        private readonly ShortsDetector $shorts,
    ) {}

    /**
     * @param  Collection<int, Video>  $videos
     */
    public function enrich(Collection $videos): void
    {
        if ($videos->isEmpty()) {
            return;
        }

        $details = $this->api->videos(array_values(
            $videos->map(fn (Video $video): string => $video->youtube_video_id)->all()
        ));

        foreach ($videos as $video) {
            $detail = $details->get($video->youtube_video_id);

            $detail instanceof VideoData
                ? $this->apply($video, $detail)
                : $this->markUnavailable($video);
        }
    }

    /**
     * Shorts detection is separate from the API, so it still runs when there
     * is no key. It is skipped for anything the feed will never show.
     *
     * @param  Collection<int, Video>  $videos
     */
    public function detectShorts(Collection $videos): void
    {
        foreach ($videos as $video) {
            if ($video->isUnavailable() || $video->live_status !== LiveStatus::None) {
                continue;
            }

            $isShort = $this->shorts->isShort($video);

            if ($isShort !== $video->is_short) {
                $video->forceFill(['is_short' => $isShort])->save();
            }
        }
    }

    private function apply(Video $video, VideoData $detail): void
    {
        $video->forceFill([
            'duration_seconds' => $detail->durationSeconds,
            'live_status' => $detail->liveStatus,
            'scheduled_start_at' => $detail->scheduledStartAt,
            'unavailable_at' => null,
            'enriched_at' => now(),
        ])->save();
    }

    /**
     * Sent to YouTube but not returned: deleted, private or blocked. The
     * archived title, description and thumbnail stay readable.
     */
    private function markUnavailable(Video $video): void
    {
        $video->forceFill(['unavailable_at' => now()])->save();
    }
}
