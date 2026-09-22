<?php

use App\Models\Video;

it('sends the root url to the feed', function (): void {
    $this->actingAs(calmUser())
        ->get('/')
        ->assertRedirect(route('feed'));
});

it('works with no YouTube API key configured', function (): void {
    config()->set('calm-tube.api_key', null);
    Video::factory()->for(calmChannel())->create();

    $this->actingAs(calmUser())
        ->get(route('feed'))
        ->assertOk();
});

it('defines the settings the app tunes itself with', function (string $key): void {
    expect(config()->has($key))->toBeTrue();
})->with([
    'calm-tube.refresh.timeout',
    'calm-tube.refresh.retries',
    'calm-tube.shorts.probe',
    'calm-tube.shorts.probe_max_seconds',
    'calm-tube.shorts.fallback_max_seconds',
    'calm-tube.images.preferred',
    'calm-tube.images.disk',
    'calm-tube.feed.per_page',
]);

it('prefers the largest thumbnail and falls back to the smallest 16:9 one', function (): void {
    expect(config('calm-tube.images.preferred'))->toBe(['maxresdefault', 'mqdefault']);
});

it('sets the Shorts thresholds to keep genuinely short videos', function (): void {
    expect(config('calm-tube.shorts.probe_max_seconds'))->toBe(180)
        ->and(config('calm-tube.shorts.fallback_max_seconds'))->toBe(60);
});

it('writes refresh activity to its own log channel', function (): void {
    expect(config('logging.channels.calm'))->not->toBeNull();
});
