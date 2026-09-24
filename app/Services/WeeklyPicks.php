<?php

namespace App\Services;

use App\Ai\Agents\WeeklyPicker;
use App\Models\Channel;
use App\Models\ChannelDigest;
use App\Models\Video;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Holds a channel's uploads until its week is over, then keeps the best few.
 *
 * For channels where even a couple a day is too many. Through the week their
 * uploads stay out of the feed. The first refresh after the week closes puts
 * the week to a local model, judged against the note you wrote for the
 * channel and what you have finished, abandoned and hidden from it before,
 * and releases what it chooses. The decision is recorded, so a week is never
 * picked over twice and a pick never changes under you.
 *
 * Degrades like everything else. With the model unreachable the week stays
 * held and the next refresh asks again; after a grace period the longest are
 * kept instead. Only the most recently closed week is ever put to the model,
 * so switching a channel with years of history to weekly is not hundreds of
 * calls: everything older is settled by length.
 *
 * Nothing is deleted. Held and passed-over videos are still archived and
 * still on the channel page, exactly like the daily limit's.
 */
class WeeklyPicks
{
    /**
     * Recalculates the weeks the given videos fall in.
     *
     * @param  Collection<int, Video>  $videos
     */
    public function applyTo(Channel $channel, Collection $videos): int
    {
        return $this->apply($channel, array_values($videos
            ->map(fn (Video $video): string => $this->weekOf($video->published_at)->toDateString())
            ->unique()
            ->all()));
    }

    /**
     * Recalculates whole weeks, named by their Monday, or every week the
     * channel has uploads in when none are named.
     *
     * @param  list<string>|null  $weeks
     */
    public function apply(Channel $channel, ?array $weeks = null): int
    {
        $outOfFeed = 0;

        foreach ($weeks ?? $this->weeksWithUploads($channel) as $week) {
            $outOfFeed += $this->applyToWeek($channel, CarbonImmutable::parse($week));
        }

        return $outOfFeed;
    }

    /**
     * Decides any closed week still waiting on a decision.
     *
     * Run on every refresh, including the ones that find nothing new: a week
     * closes on the calendar, not when the channel next uploads.
     */
    public function settle(Channel $channel): int
    {
        $thisWeek = $this->weekOf(Date::now());

        $waiting = $channel->videos()
            ->viewable()
            ->unsnoozed()
            ->untouched()
            ->setAside()
            ->where('published_at', '<', $thisWeek)
            ->pluck('published_at')
            ->map(fn (mixed $publishedAt): string => $this->weekOf(CarbonImmutable::parse($publishedAt))->toDateString())
            ->unique();

        $decided = $channel->digests()
            ->pluck('week_starts_on')
            ->map(fn (mixed $week): string => CarbonImmutable::parse($week)->toDateString());

        return $this->apply($channel, array_values($waiting->diff($decided)->all()));
    }

    private function applyToWeek(Channel $channel, CarbonImmutable $start): int
    {
        $ofTheWeek = fn () => $channel->videos()
            ->viewable()
            ->unsnoozed()
            ->where('published_at', '>=', $start)
            ->where('published_at', '<', $start->addWeek());

        // Yours, not the rule's. They also spend the week's budget.
        $touched = $ofTheWeek()->touched()->get();
        $this->mark($touched, null);

        /** @var Collection<int, Video> $untouched */
        $untouched = $ofTheWeek()->untouched()->get();

        if ($untouched->isEmpty()) {
            return 0;
        }

        $digest = $this->isClosed($start)
            ? $this->digestFor($channel, $start) ?? $this->decide($channel, $start, $touched->count(), $untouched)
            : null;

        if (! $digest instanceof ChannelDigest) {
            // Still open, or waiting for the model: held.
            $this->mark($untouched, Date::now());

            return $untouched->count();
        }

        $picked = $untouched->filter(fn (Video $video): bool => in_array($video->getKey(), $digest->picked_video_ids, true));
        $passedOver = $untouched->diff($picked);

        $this->mark($picked, null);
        $this->mark($passedOver, Date::now());

        return $passedOver->count();
    }

    /**
     * Makes the week's decision, or returns null to keep holding it.
     *
     * @param  Collection<int, Video>  $candidates
     */
    private function decide(Channel $channel, CarbonImmutable $start, int $touched, Collection $candidates): ?ChannelDigest
    {
        $slots = max((int) $channel->sample_limit - $touched, 0);

        if ($slots === 0) {
            return $this->record($channel, $start, ChannelDigest::METHOD_BUDGET, []);
        }

        if ($start->equalTo($this->lastClosedWeek())) {
            $picks = $this->ask($channel, $candidates, $slots);

            if ($picks !== null) {
                foreach ($picks as $id => $reason) {
                    Video::query()->whereKey($id)->update(['pick_reason' => $reason]);
                }

                return $this->record(
                    $channel,
                    $start,
                    ChannelDigest::METHOD_AI,
                    array_keys($picks),
                    (string) config('calm-tube.ai.model'),
                );
            }

            $graceEnds = $start->addWeek()->addDays((int) config('calm-tube.weekly.grace_days'));

            if (Date::now()->lt($graceEnds)) {
                return null;
            }
        }

        $longest = array_values($candidates
            ->sortByDesc(fn (Video $video): int => $video->duration_seconds ?? -1)
            ->take($slots)
            ->map(fn (Video $video): int => $video->getKey())
            ->all());

        return $this->record($channel, $start, ChannelDigest::METHOD_LENGTH, $longest);
    }

    /**
     * Puts the week to the model.
     *
     * Returns the chosen videos' reasons keyed by video id, or null when the
     * model could not be reached or gave nothing usable. Numbers it made up
     * are dropped, and it can never choose more than the limit.
     *
     * @param  Collection<int, Video>  $candidates
     * @return array<int, string>|null
     */
    private function ask(Channel $channel, Collection $candidates, int $slots): ?array
    {
        $numbered = $candidates->sortBy('published_at')->values();

        try {
            $response = WeeklyPicker::make()->prompt(
                $this->promptFor($channel, $numbered, $slots),
                provider: (string) config('calm-tube.ai.provider'),
                model: (string) config('calm-tube.ai.model'),
                timeout: (int) config('calm-tube.ai.timeout'),
            );

            $answer = $response instanceof StructuredAgentResponse ? $response['picks'] ?? [] : [];
        } catch (Throwable $exception) {
            Log::channel('calm')->warning('Weekly pick could not ask the model', [
                'channel_id' => $channel->youtube_channel_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $picks = [];

        foreach (is_array($answer) ? $answer : [] as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $video = $numbered->get((int) ($pick['number'] ?? 0) - 1);

            if ($video instanceof Video && ! isset($picks[$video->getKey()]) && count($picks) < $slots) {
                $picks[$video->getKey()] = Str::limit(trim((string) ($pick['reason'] ?? '')), 300);
            }
        }

        Log::channel('calm')->info('Weekly pick made', [
            'channel_id' => $channel->youtube_channel_id,
            'candidates' => $numbered->count(),
            'picked' => count($picks),
        ]);

        return $picks;
    }

    /**
     * @param  Collection<int, Video>  $numbered
     */
    private function promptFor(Channel $channel, Collection $numbered, int $slots): string
    {
        $history = (int) config('calm-tube.weekly.history');
        $titles = fn ($query): string => $query->limit($history)->pluck('title')
            ->map(fn (string $title): string => '- '.$title)
            ->whenEmpty(fn (Collection $none): Collection => $none->push('- (none yet)'))
            ->join("\n");

        $uploads = $numbered->map(fn (Video $video, int $index): string => sprintf(
            "%d. %s%s\n   %s",
            $index + 1,
            $video->duration_seconds === null ? '' : '['.max(1, intdiv($video->duration_seconds, 60)).' min] ',
            $video->title,
            Str::limit(Str::squish((string) $video->description), (int) config('calm-tube.weekly.description_chars')),
        ))->join("\n");

        $note = filled($channel->sample_note) ? $channel->sample_note : '(no note given)';

        return <<<TEXT
        Channel: {$channel->display_name}

        What I want from this channel:
        {$note}

        Videos from it I watched to the end:
        {$titles($channel->videos()->whereNotNull('watched_at')->latest('watched_at'))}

        Videos from it I started but did not finish:
        {$titles($channel->videos()->whereNull('watched_at')->whereNotNull('resume_seconds')->latest('updated_at'))}

        Videos from it I hid:
        {$titles($channel->videos()->whereNotNull('hidden_at')->latest('hidden_at'))}

        Last week's uploads. Choose at most {$slots}:
        {$uploads}
        TEXT;
    }

    /**
     * @param  list<int>  $pickedVideoIds
     */
    private function record(Channel $channel, CarbonImmutable $start, string $method, array $pickedVideoIds, ?string $model = null): ChannelDigest
    {
        return $channel->digests()->create([
            'week_starts_on' => $start->toDateString(),
            'method' => $method,
            'model' => $model,
            'picked_video_ids' => $pickedVideoIds,
        ]);
    }

    private function digestFor(Channel $channel, CarbonImmutable $start): ?ChannelDigest
    {
        return $channel->digests()->whereDate('week_starts_on', $start->toDateString())->first();
    }

    /**
     * @param  Collection<int, Video>  $videos
     */
    private function mark(Collection $videos, mixed $value): void
    {
        // Only rows whose state changes, so a week recalculated to the same
        // answer writes nothing, and a held video keeps the time it was held.
        $ids = $videos
            ->filter(fn (Video $video): bool => ($video->sampled_out_at !== null) !== ($value !== null))
            ->map(fn (Video $video): int => $video->getKey())
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        Video::query()->whereKey($ids)->update(['sampled_out_at' => $value]);
    }

    /**
     * @return list<string>
     */
    private function weeksWithUploads(Channel $channel): array
    {
        return array_values($channel->videos()
            ->viewable()
            ->unsnoozed()
            ->pluck('published_at')
            ->map(fn (mixed $publishedAt): string => $this->weekOf(CarbonImmutable::parse($publishedAt))->toDateString())
            ->unique()
            ->all());
    }

    private function isClosed(CarbonImmutable $start): bool
    {
        return $start->addWeek()->lte(Date::now());
    }

    private function lastClosedWeek(): CarbonImmutable
    {
        return $this->weekOf(Date::now())->subWeek();
    }

    private function weekOf(\DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->startOfWeek(CarbonImmutable::MONDAY);
    }
}
