<?php

namespace App\Console\Commands;

use App\Enums\LiveStatus;
use App\Exceptions\YouTubeException;
use App\Models\Video;
use App\Services\VideoEnricher;
use App\Services\YouTube\DataApiClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Retries anything the API could not tell us about last time, and re-checks
 * streams that were still running.
 *
 * This is what makes a missing key or an exhausted quota self-healing: the
 * videos are already stored, and their durations fill in later without you
 * doing anything.
 */
class EnrichVideosCommand extends Command
{
    protected $signature = 'calm:enrich';

    protected $description = 'Fill in durations and live status for videos still missing them';

    public function handle(DataApiClient $api, VideoEnricher $enricher): int
    {
        if (! $api->isConfigured()) {
            $this->info('No YouTube API key configured, so there is nothing to enrich.');

            return self::SUCCESS;
        }

        $videos = $this->pending();

        if ($videos->isEmpty()) {
            $this->info('Everything is already up to date.');

            return self::SUCCESS;
        }

        foreach ($videos->chunk(50) as $batch) {
            try {
                $enricher->enrich($batch);
            } catch (YouTubeException $exception) {
                $this->error($exception->getMessage());

                return self::SUCCESS;
            }

            $enricher->detectShorts($batch);
        }

        $count = $videos->count();
        $this->info(sprintf('Checked %d %s.', $count, Str::plural('video', $count)));

        return self::SUCCESS;
    }

    /**
     * Videos with no API data yet, plus streams that may since have finished.
     *
     * Videos already known to be gone are left alone, so a deleted video is
     * not looked up forever.
     *
     * @return Collection<int, Video>
     */
    private function pending()
    {
        return Video::query()
            ->whereNull('unavailable_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('enriched_at')
                ->orWhereIn('live_status', [LiveStatus::Live, LiveStatus::Upcoming]))
            ->get();
    }
}
