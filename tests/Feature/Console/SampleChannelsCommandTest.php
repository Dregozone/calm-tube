<?php

use App\Models\Channel;
use App\Models\Video;

it('re-applies a limit across a channel whole history', function (): void {
    $channel = Channel::factory()->sampled(1)->create();

    foreach ([40, 10, 5] as $index => $minutes) {
        Video::factory()->for($channel)->create([
            'duration_seconds' => $minutes * 60,
            'published_at' => '2026-09-22 1'.$index.':00:00',
        ]);
    }

    $this->artisan('calm:sample')->assertSuccessful();

    expect(Video::inFeed()->count())->toBe(1);
});

it('picks up durations that arrived after the refresh', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    $long = Video::factory()->for($channel)->create([
        'duration_seconds' => null,
        'published_at' => '2026-09-22 10:00:00',
    ]);
    Video::factory()->for($channel)->create([
        'duration_seconds' => 120,
        'published_at' => '2026-09-22 11:00:00',
    ]);

    // calm:enrich fills this in later, which is exactly the gap this closes.
    $long->forceFill(['duration_seconds' => 4200])->save();

    $this->artisan('calm:sample')->assertSuccessful();

    expect(Video::inFeed()->pluck('id')->all())->toBe([$long->id]);
});

it('can be pointed at one channel', function (): void {
    $wanted = Channel::factory()->sampled(1)->create();
    $other = Channel::factory()->sampled(1)->create();

    foreach ([$wanted, $other] as $channel) {
        Video::factory()->for($channel)->count(2)->create([
            'duration_seconds' => 600,
            'published_at' => '2026-09-22 10:00:00',
        ]);
    }

    $this->artisan('calm:sample', ['--channel' => $wanted->youtube_channel_id])->assertSuccessful();

    expect($wanted->videos()->setAside()->count())->toBe(1)
        ->and($other->videos()->setAside()->count())->toBe(0);
});

it('says so when nothing is being sampled', function (): void {
    Channel::factory()->create();

    $this->artisan('calm:sample')
        ->expectsOutputToContain('No channels are being sampled')
        ->assertSuccessful();
});

it('reports what it set aside', function (): void {
    $channel = Channel::factory()->sampled(1)->create();
    Video::factory()->for($channel)->count(3)->create([
        'duration_seconds' => 600,
        'published_at' => '2026-09-22 10:00:00',
    ]);

    $this->artisan('calm:sample')->expectsOutputToContain('2 videos set aside');
});
