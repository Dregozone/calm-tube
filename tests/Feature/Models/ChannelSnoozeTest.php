<?php

use App\Models\Video;
use App\Services\ChannelRefresher;
use App\Services\ChannelSampler;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-09-24 09:00:00');
});

it('keeps what is already in the feed when a channel is snoozed', function (): void {
    $channel = calmChannel();
    $existing = Video::factory()->for($channel)->create(['published_at' => now()->subDay()]);

    $channel->snooze();

    expect(Video::inFeed()->pluck('id')->all())->toBe([$existing->id]);
});

it('keeps anything published during the snooze out of the feed, even after it ends', function (): void {
    $channel = calmChannel();
    $channel->snooze();

    $during = Video::factory()->for($channel)->create(['published_at' => now()->addDays(3)]);
    $this->travelTo('2026-10-02 09:00:00');
    $after = Video::factory()->for($channel)->create(['published_at' => now()]);

    expect($during->fresh()->snoozed_at)->not->toBeNull()
        ->and(Video::inFeed()->pluck('id')->all())->toBe([$after->id]);
});

it('keeps a video published during the snooze out when it is only discovered later', function (): void {
    $channel = calmChannel();
    $channel->snooze();
    $this->travelTo('2026-10-05 09:00:00');

    $lateFind = Video::factory()->for($channel)->create(['published_at' => '2026-09-26 12:00:00']);

    expect($lateFind->snoozed_at)->not->toBeNull()
        ->and(Video::inFeed()->count())->toBe(0);
});

it('stamps videos a refresh stores while the channel is snoozed', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh();
    $channel = calmChannel(['snoozed_from' => '2020-01-01', 'snoozed_until' => now()->addWeek()]);

    app(ChannelRefresher::class)->refresh($channel);

    expect($channel->videos()->count())->toBeGreaterThan(0)
        ->and($channel->videos()->whereNull('snoozed_at')->count())->toBe(0)
        ->and(Video::inFeed()->count())->toBe(0);
});

it('does not let snoozed videos take a place under the channel\'s daily limit', function (): void {
    $channel = calmChannel(['sample_limit' => 1]);
    $kept = Video::factory()->for($channel)->create(['published_at' => '2026-09-24 06:00:00', 'duration_seconds' => 600]);
    $channel->snooze();
    Video::factory()->for($channel)->create(['published_at' => '2026-09-24 10:00:00', 'duration_seconds' => 3600]);

    app(ChannelSampler::class)->apply($channel);

    expect(Video::inFeed()->pluck('id')->all())->toBe([$kept->id]);
});

it('lets new uploads back in once woken early', function (): void {
    $channel = calmChannel();
    $channel->snooze();
    $this->travelTo('2026-09-25 09:00:00');

    $channel->wake();
    $this->travelTo('2026-09-25 10:00:00');
    $after = Video::factory()->for($channel)->create(['published_at' => now()]);

    expect($channel->isSnoozed())->toBeFalse()
        ->and(Video::inFeed()->pluck('id')->all())->toBe([$after->id]);
});

it('extends a snooze from now without moving its start', function (): void {
    $channel = calmChannel();
    $channel->snooze();
    $this->travelTo('2026-09-27 09:00:00');

    $channel->snooze();

    expect($channel->snoozed_from->toDateString())->toBe('2026-09-24')
        ->and($channel->snoozed_until->toDateString())->toBe('2026-10-04');
});

it('snoozes a channel from a card in the feed', function (): void {
    $video = Video::factory()->for(calmChannel())->create();

    Livewire::test('video-card', ['video' => $video])
        ->call('snoozeChannel')
        ->assertDispatched('feed-changed');

    expect($video->channel->fresh()->isSnoozed())->toBeTrue();
});

it('snoozes and wakes a channel from the channel list', function (): void {
    $channel = calmChannel();

    Livewire::test('pages::channels.index')
        ->call('snooze', $channel->id)
        ->assertSee('snoozed until Thu 1 Oct');

    expect($channel->fresh()->isSnoozed())->toBeTrue();

    Livewire::test('pages::channels.index')->call('wake', $channel->id);

    expect($channel->fresh()->isSnoozed())->toBeFalse();
});

it('snoozes and wakes a channel from its page', function (): void {
    $channel = calmChannel();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->call('snooze')
        ->assertSee('Wake up');

    expect($channel->fresh()->isSnoozed())->toBeTrue();

    Livewire::test('pages::channels.show', ['channel' => $channel->fresh()])
        ->call('wake')
        ->assertSee('Snooze 7 days');

    expect($channel->fresh()->isSnoozed())->toBeFalse();
});
