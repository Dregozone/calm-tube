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
     * Records a consent choice so YouTube serves the page instead of bouncing
     * the request to its consent interstitial, which it does for every EU and
     * UK request that arrives without one. It carries no account and no
     * identity; without it the probe cannot see the answer at all.
     */
    private const string CONSENT_COOKIE = 'SOCS=CAI';

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
                ->withHeaders(['Cookie' => self::CONSENT_COOKIE])
                ->timeout(self::PROBE_TIMEOUT)
                ->head("https://www.youtube.com/shorts/{$videoId}");
        } catch (ConnectionException) {
            Log::channel('calm')->warning('Shorts probe failed', ['video_id' => $videoId]);

            return null;
        }

        if ($response->status() === 200) {
            return true;
        }

        // Only a redirect to the watch page means "ordinary video". Anything
        // else redirecting — the consent interstitial, a sign-in wall — says
        // nothing about the video, and must not be read as an answer.
        if ($this->redirectsToWatchPage($response->header('Location'))) {
            return false;
        }

        Log::channel('calm')->warning('Shorts probe was inconclusive', [
            'video_id' => $videoId,
            'status' => $response->status(),
            'location' => $response->header('Location'),
        ]);

        return null;
    }

    private function redirectsToWatchPage(?string $location): bool
    {
        if ($location === null || $location === '') {
            return false;
        }

        return parse_url($location, PHP_URL_HOST) === 'www.youtube.com'
            && parse_url($location, PHP_URL_PATH) === '/watch';
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
