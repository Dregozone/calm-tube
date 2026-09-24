<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the best of a noisy channel's day and sets the rest aside.
 *
 * Some channels publish one substantial piece and a dozen offcuts of it in a
 * single day. Following them means either drowning or unfollowing, and both
 * lose you the good one. This keeps the longest few uploads of each day and
 * leaves the rest out of the feed.
 *
 * Length is the whole of the rule, and deliberately so: on the channels this
 * is for, it separates the talk from the clips cut out of it almost perfectly,
 * it needs no model and no history of what you like, and it can be explained
 * in one sentence. It is worth nothing on a channel whose uploads are all the
 * same length, which is why it is off unless you turn it on.
 *
 * Nothing is deleted or hidden. A video set aside is still archived, still on
 * its channel page, and comes straight back if the limit is lifted.
 */
class ChannelSampler
{
    public function __construct(private readonly WeeklyPicks $weekly) {}

    /**
     * Applies the channel's limit to the days the given videos fall on.
     *
     * The unit is the publication day rather than the refresh that found
     * them: refreshes are an implementation detail, days are not, and a day
     * gives the same answer however many times it is recalculated.
     *
     * @param  Collection<int, Video>  $videos
     */
    public function applyTo(Channel $channel, Collection $videos): void
    {
        if ($videos->isEmpty()) {
            return;
        }

        if ($channel->isPickedWeekly()) {
            $this->weekly->applyTo($channel, $videos);

            return;
        }

        $days = $videos
            ->map(fn (Video $video): string => $video->published_at->toDateString())
            ->unique()
            ->all();

        $this->apply($channel, array_values($days));
    }

    /**
     * Recalculates whole days, or the channel's entire history when no days
     * are named — which is what changing the limit needs.
     *
     * @param  list<string>|null  $days
     */
    public function apply(Channel $channel, ?array $days = null): int
    {
        if ($channel->sample_limit === null) {
            return $this->clear($channel);
        }

        if ($channel->isPickedWeekly()) {
            return $this->weekly->apply($channel, $days === null ? null : array_values(array_unique(array_map(
                fn (string $day): string => Date::parse($day)->startOfWeek()->toDateString(),
                $days,
            ))));
        }

        $setAside = 0;

        foreach ($this->days($channel, $days) as $day) {
            $setAside += $this->applyToDay($channel, $day);
        }

        if ($setAside > 0) {
            Log::channel('calm')->info('Sampled a channel', [
                'channel_id' => $channel->youtube_channel_id,
                'limit' => $channel->sample_limit,
                'set_aside' => $setAside,
            ]);
        }

        return $setAside;
    }

    /**
     * Decides any week a weekly channel has finished, whether or not this
     * refresh found anything new.
     */
    public function settle(Channel $channel): void
    {
        if ($channel->isPickedWeekly()) {
            $this->weekly->settle($channel);
        }
    }

    /**
     * Puts everything back, for a channel no longer being sampled.
     */
    public function clear(Channel $channel): int
    {
        return $channel->videos()
            ->whereNotNull('sampled_out_at')
            ->update(['sampled_out_at' => null]);
    }

    /**
     * Ranks one day's uploads longest first and sets aside everything past
     * the limit.
     *
     * Recalculated from scratch every time, so a longer video arriving later
     * in the day takes its place and the shortest of the day's keepers drops
     * out. The rule is always "the longest few this channel published that
     * day", not "the first few we happened to see".
     *
     * Two things are never set aside. A video whose duration is unknown,
     * because the API may not have answered yet and dropping a video for
     * being unmeasurable is the one outcome this must not produce. And a
     * video you have watched or started, because a rule that recalculates
     * must not take back something you had already opened.
     */
    private function applyToDay(Channel $channel, string $day): int
    {
        $ofTheDay = fn () => $channel->videos()->viewable()->whereDate('published_at', $day);

        // Yours, not the rule's — and brought back if the rule had it.
        $this->mark($ofTheDay()->touched()->get(), null);

        /** @var Collection<int, Video> $measured */
        $measured = $ofTheDay()
            ->untouched()
            ->whereNotNull('duration_seconds')
            ->orderByDesc('duration_seconds')
            ->orderByDesc('published_at')
            ->get();

        $keep = $measured->take($channel->sample_limit);
        $setAside = $measured->skip($channel->sample_limit);

        // Rewritten both ways, so a raised limit brings videos back without
        // anyone having to remember to ask.
        $this->mark($keep, null);
        $this->mark($setAside, now());

        return $setAside->count();
    }

    /**
     * @param  Collection<int, Video>  $videos
     */
    private function mark(Collection $videos, mixed $value): void
    {
        // Only the rows whose state actually changes, so a recalculation
        // that decides nothing writes nothing.
        $ids = $videos
            ->filter(fn (Video $video): bool => ($video->sampled_out_at !== null) !== ($value !== null))
            ->map(fn (Video $video): int => $video->getKey())
            ->all();

        if ($ids === []) {
            return;
        }

        Video::query()->whereKey(array_values($ids))->update(['sampled_out_at' => $value]);
    }

    /**
     * @param  list<string>|null  $days
     * @return list<string>
     */
    private function days(Channel $channel, ?array $days): array
    {
        if ($days !== null) {
            return $days;
        }

        $days = $channel->videos()
            ->viewable()
            ->selectRaw('date(published_at) as day')
            ->distinct()
            ->pluck('day')
            ->map(fn (mixed $day): string => (string) $day)
            ->all();

        return array_values($days);
    }
}
