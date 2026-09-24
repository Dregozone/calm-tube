<?php

use App\Ai\Agents\WeeklyPicker;
use App\Models\Channel;
use App\Models\ChannelDigest;
use App\Models\Video;
use App\Services\ChannelSampler;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Last week ran Monday 14 to Sunday 20 September; it is now Tuesday 22nd.
 */
beforeEach(function (): void {
    $this->travelTo('2026-09-22 09:00:00');
    $this->channel = Channel::factory()->pickedWeekly(2)->create();
});

function weeklyUpload(Channel $channel, string $publishedAt, int $minutes = 20, array $attributes = []): Video
{
    return Video::factory()->for($channel)->create([
        'title' => "{$minutes} minutes from {$publishedAt}",
        'duration_seconds' => $minutes * 60,
        'published_at' => $publishedAt,
        ...$attributes,
    ]);
}

function settleWeeks(Channel $channel): void
{
    $sampler = app(ChannelSampler::class);
    $sampler->apply($channel);
    $sampler->settle($channel);
}

it('holds this week\'s uploads out of the feed until the week is over', function (): void {
    WeeklyPicker::fake()->preventStrayPrompts();
    weeklyUpload($this->channel, '2026-09-21 10:00:00');
    weeklyUpload($this->channel, '2026-09-22 08:00:00');

    settleWeeks($this->channel);

    expect(Video::inFeed()->count())->toBe(0)
        ->and(ChannelDigest::count())->toBe(0);
    WeeklyPicker::assertNeverPrompted();
});

it('releases what the model picks once the week is over, with its reason', function (): void {
    $first = weeklyUpload($this->channel, '2026-09-14 10:00:00');
    $second = weeklyUpload($this->channel, '2026-09-16 10:00:00');
    $third = weeklyUpload($this->channel, '2026-09-18 10:00:00');
    WeeklyPicker::fake([['picks' => [['number' => 2, 'reason' => 'A full breakdown of pricing.']]]]);

    settleWeeks($this->channel);

    expect(Video::inFeed()->pluck('id')->all())->toBe([$second->id])
        ->and($second->fresh()->pick_reason)->toBe('A full breakdown of pricing.')
        ->and($first->fresh()->sampled_out_at)->not->toBeNull()
        ->and($third->fresh()->sampled_out_at)->not->toBeNull()
        ->and(ChannelDigest::sole()->method)->toBe(ChannelDigest::METHOD_AI);
});

it('releases nothing when the model thinks nothing is worth it', function (): void {
    weeklyUpload($this->channel, '2026-09-15 10:00:00');
    WeeklyPicker::fake([['picks' => []]]);

    settleWeeks($this->channel);

    expect(Video::inFeed()->count())->toBe(0)
        ->and(ChannelDigest::sole()->picked_video_ids)->toBe([]);
});

it('keeps to the limit and drops numbers the model made up', function (): void {
    $uploads = collect(range(14, 17))->map(fn (int $day): Video => weeklyUpload($this->channel, "2026-09-{$day} 10:00:00"));
    WeeklyPicker::fake([['picks' => [
        ['number' => 9, 'reason' => 'Invented.'],
        ['number' => 1, 'reason' => 'One.'],
        ['number' => 1, 'reason' => 'One again.'],
        ['number' => 3, 'reason' => 'Three.'],
        ['number' => 4, 'reason' => 'Four.'],
    ]]]);

    settleWeeks($this->channel);

    expect(Video::inFeed()->pluck('id')->sort()->values()->all())
        ->toBe([$uploads[0]->id, $uploads[2]->id]);
});

it('keeps holding the week while the model cannot be reached', function (): void {
    weeklyUpload($this->channel, '2026-09-15 10:00:00');
    WeeklyPicker::fake(fn () => throw ProviderConnectionException::forProvider('ollama'));

    settleWeeks($this->channel);

    expect(Video::inFeed()->count())->toBe(0)
        ->and(ChannelDigest::count())->toBe(0);
});

it('keeps the longest once the model has been unreachable past the grace period', function (): void {
    $this->travelTo('2026-09-23 09:00:00');
    weeklyUpload($this->channel, '2026-09-14 10:00:00', minutes: 5);
    $longest = weeklyUpload($this->channel, '2026-09-15 10:00:00', minutes: 50);
    $next = weeklyUpload($this->channel, '2026-09-16 10:00:00', minutes: 30);
    WeeklyPicker::fake(fn () => throw ProviderConnectionException::forProvider('ollama'));

    settleWeeks($this->channel);

    expect(Video::inFeed()->pluck('id')->sort()->values()->all())->toBe([$longest->id, $next->id])
        ->and(ChannelDigest::sole()->method)->toBe(ChannelDigest::METHOD_LENGTH);
});

it('counts videos you opened against the week and keeps them in the feed', function (): void {
    $watched = weeklyUpload($this->channel, '2026-09-14 10:00:00', attributes: ['watched_at' => now()]);
    weeklyUpload($this->channel, '2026-09-15 10:00:00');
    weeklyUpload($this->channel, '2026-09-16 10:00:00');
    WeeklyPicker::fake([['picks' => [['number' => 1, 'reason' => 'One.'], ['number' => 2, 'reason' => 'Two.']]]]);

    settleWeeks($this->channel);

    expect(Video::inFeed()->count())->toBe(2)
        ->and(Video::inFeed()->pluck('id')->all())->toContain($watched->id);
    WeeklyPicker::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Choose at most 1'));
});

it('never puts a decided week to the model twice', function (): void {
    weeklyUpload($this->channel, '2026-09-15 10:00:00');
    WeeklyPicker::fake([['picks' => [['number' => 1, 'reason' => 'One.']]]]);

    settleWeeks($this->channel);
    settleWeeks($this->channel->fresh());

    WeeklyPicker::assertPromptedTimes(1);
    expect(Video::inFeed()->count())->toBe(1);
});

it('settles weeks older than the last one by length, without asking', function (): void {
    weeklyUpload($this->channel, '2026-08-31 10:00:00', minutes: 5);
    $longest = weeklyUpload($this->channel, '2026-09-01 10:00:00', minutes: 50);
    WeeklyPicker::fake()->preventStrayPrompts();

    settleWeeks($this->channel);

    WeeklyPicker::assertNeverPrompted();
    expect(Video::inFeed()->pluck('id')->all())->toContain($longest->id)
        ->and(ChannelDigest::sole()->method)->toBe(ChannelDigest::METHOD_LENGTH);
});

it('shows the model your note and what you did with the channel before', function (): void {
    weeklyUpload($this->channel, '2026-09-01 10:00:00', attributes: ['title' => 'The one I finished', 'watched_at' => now()]);
    weeklyUpload($this->channel, '2026-09-02 10:00:00', attributes: ['title' => 'The one I hid', 'hidden_at' => now()]);
    weeklyUpload($this->channel, '2026-09-15 10:00:00', attributes: ['title' => 'Up for the pick']);
    WeeklyPicker::fake([['picks' => []]]);

    settleWeeks($this->channel);

    WeeklyPicker::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Long-form business teaching.')
        && $prompt->contains('The one I finished')
        && $prompt->contains('The one I hid')
        && $prompt->contains('1. [20 min] Up for the pick'));
});
