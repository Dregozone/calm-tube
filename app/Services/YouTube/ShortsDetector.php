<?php

namespace App\Services\YouTube;

use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a video is a Short.
 *
 * Neither the RSS feed nor the Data API says so, and duration alone cannot
 * tell them apart: YouTube raised the Shorts limit to three minutes, which is
 * exactly the range real short videos live in.
 *
 * The signal that works is the /shorts/ URL. A genuine Short answers 200;
 * anything else redirects to /watch. It costs no quota, and the duration gate
 * means it is only ever asked about videos short enough to be candidates.
 */
class ShortsDetector
{
    private const int PROBE_TIMEOUT = 5;

    /**
     * Null means detection could not decide, and an undecided video is shown.
     */
    public function isShort(Video $video): ?bool
    {
        // Already decided. A Short never stops being one.
        if ($video->is_short !== null) {
            return $video->is_short;
        }

        $duration = $video->duration_seconds;

        if ($duration !== null && $duration > $this->ceiling()) {
            return false;
        }

        if (config('calm-tube.shorts.probe')) {
            $probed = $this->probe($video->youtube_video_id);

            if ($probed !== null) {
                return $probed;
            }
        }

        return $this->fromDuration($duration);
    }

    private function probe(string $videoId): ?bool
    {
        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(self::PROBE_TIMEOUT)
                ->head("https://www.youtube.com/shorts/{$videoId}");
        } catch (ConnectionException) {
            Log::channel('calm')->warning('Shorts probe failed', ['video_id' => $videoId]);

            return null;
        }

        if ($response->status() === 200) {
            return true;
        }

        // Redirected to the watch page, so an ordinary video.
        if ($response->status() >= 300 && $response->status() < 400) {
            return false;
        }

        Log::channel('calm')->warning('Shorts probe was inconclusive', [
            'video_id' => $videoId,
            'status' => $response->status(),
        ]);

        return null;
    }

    /**
     * The conservative fallback, deliberately generous to real short videos:
     * only a minute or less counts without the probe to confirm it.
     */
    private function fromDuration(?int $duration): ?bool
    {
        if ($duration === null) {
            return null;
        }

        return $duration <= (int) config('calm-tube.shorts.fallback_max_seconds');
    }

    private function ceiling(): int
    {
        return (int) config('calm-tube.shorts.probe_max_seconds');
    }
}
