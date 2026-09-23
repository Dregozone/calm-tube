<?php

use App\Models\Channel;
use App\Models\Video;
use App\Services\ChannelSampler;
use Illuminate\Support\Collection;

function sampler(): ChannelSampler
{
    return app(ChannelSampler::class);
}

/**
 * @param  list<int>  $minutes
 * @return Collection<int, Video>
 */
function uploadsOn(Channel $channel, string $day, array $minutes)
{
    return collect($minutes)->map(fn (int $mins, int $i): Video => Video::factory()
        ->for($channel)
        ->create([
            'title' => "{$mins} minute video",
            'duration_seconds' => $mins * 60,
            'published_at' => $day.' 1'.$i.':00:00',
        ]));
}

it('keeps the longest of a day and sets the rest aside', function (): void {
    $channel = Channel::factory()->sampled(3)->create();
    uploadsOn($channel, '2026-09-22', [71, 12, 4, 3, 2, 2, 1]);

    sampler()->apply($channel);

    expect(Video::inFeed()->pluck('duration_seconds')->all())->toBe([71 * 60, 12 * 60, 4 * 60]);
});

it('sets nothing aside on a day inside the limit', function (): void {
    $channel = Channel::factory()->sampled(3)->create();
    uploadsOn($channel, '2026-09-22', [40, 10]);

    sampler()->apply($channel);

    expect(Video::query()->setAside()->count())->toBe(0);
});

it('treats each day on its own', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-21', [30, 5]);
    uploadsOn($channel, '2026-09-22', [40, 6]);

    sampler()->apply($channel);

    // One kept per day, not one overall.
    expect(Video::inFeed()->count())->toBe(2)
        ->and(Video::query()->setAside()->count())->toBe(2);
});

it('never sets aside a video whose length is unknown', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-22', [40, 5]);
    $unmeasured = Video::factory()->for($channel)->create([
        'duration_seconds' => null,
        'published_at' => '2026-09-22 09:00:00',
    ]);

    // The API may not have answered yet, and silently dropping a video
    // because we could not measure it is the one thing this must not do.
    sampler()->apply($channel);

    expect($unmeasured->fresh()->isSetAside())->toBeFalse();
});

it('brings videos back when the limit is raised', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-22', [40, 20, 5]);
    sampler()->apply($channel);

    $channel->forceFill(['sample_limit' => 3])->save();
    sampler()->apply($channel->fresh());

    expect(Video::query()->setAside()->count())->toBe(0);
});

it('brings everything back when sampling is turned off', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-22', [40, 20, 5]);
    sampler()->apply($channel);

    $channel->forceFill(['sample_limit' => null])->save();
    sampler()->apply($channel->fresh());

    expect(Video::inFeed()->count())->toBe(3);
});

it('leaves an unsampled channel entirely alone', function (): void {
    $channel = Channel::factory()->create();
    uploadsOn($channel, '2026-09-22', [71, 12, 4, 3, 2]);

    sampler()->apply($channel);

    expect(Video::inFeed()->count())->toBe(5);
});

it('touches only the days the given videos fall on', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-21', [30, 5]);
    $today = uploadsOn($channel, '2026-09-22', [40, 6]);

    sampler()->applyTo($channel, $today);

    expect(Video::query()->setAside()->count())->toBe(1)
        ->and(Video::query()->setAside()->first()->duration_seconds)->toBe(6 * 60);
});

it('gives the same answer however many times it runs', function (): void {
    $channel = Channel::factory()->sampled(2)->create();
    uploadsOn($channel, '2026-09-22', [71, 12, 4, 3]);

    sampler()->apply($channel);
    $first = Video::query()->setAside()->pluck('id')->sort()->values()->all();
    sampler()->apply($channel);

    expect(Video::query()->setAside()->pluck('id')->sort()->values()->all())->toBe($first);
});

it('sets nothing aside on another channel', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    uploadsOn($channel, '2026-09-22', [40, 5]);
    $other = Channel::factory()->create();
    uploadsOn($other, '2026-09-22', [30, 3]);

    sampler()->apply($channel);

    expect($other->videos()->setAside()->count())->toBe(0);
});

describe('what a set-aside video still is', function (): void {
    it('is kept, not deleted', function (): void {
        $channel = Channel::factory()->sampled(1)->create();
        uploadsOn($channel, '2026-09-22', [40, 5]);

        sampler()->apply($channel);

        expect($channel->videos()->count())->toBe(2);
    });

    it('is still on its channel page', function (): void {
        $channel = Channel::factory()->sampled(1)->create();
        uploadsOn($channel, '2026-09-22', [40, 5]);

        sampler()->apply($channel);

        // The channel page asks the viewable question, not the feed one.
        expect(Video::query()->viewable()->count())->toBe(2)
            ->and(Video::inFeed()->count())->toBe(1);
    });

    it('is not marked watched or hidden', function (): void {
        $channel = Channel::factory()->sampled(1)->create();
        uploadsOn($channel, '2026-09-22', [40, 5]);

        sampler()->apply($channel);

        $aside = Video::query()->setAside()->first();

        expect($aside->watched_at)->toBeNull()
            ->and($aside->hidden_at)->toBeNull();
    });
});
