<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Database\UniqueConstraintViolationException;

it('displays the custom name when one is set', function (): void {
    $channel = Channel::factory()->create([
        'title' => 'Practical Engineering',
        'custom_name' => 'Bridges Guy',
    ]);

    expect($channel->display_name)->toBe('Bridges Guy');
});

it('falls back to the YouTube title when no custom name is set', function (): void {
    $channel = Channel::factory()->create([
        'title' => 'Practical Engineering',
        'custom_name' => null,
    ]);

    expect($channel->display_name)->toBe('Practical Engineering');
});

it('limits the enabled scope to channels that are enabled', function (): void {
    $enabled = Channel::factory()->create();
    $disabled = Channel::factory()->disabled()->create();

    $ids = Channel::enabled()->pluck('id')->all();

    expect($ids)->toContain($enabled->id)
        ->and($ids)->not->toContain($disabled->id);
});

it('uses the YouTube channel id as its route key', function (): void {
    $channel = Channel::factory()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);

    expect($channel->getRouteKeyName())->toBe('youtube_channel_id')
        ->and($channel->getRouteKey())->toBe(CALM_CHANNEL_ID);
});

it('rejects a second channel with the same YouTube channel id', function (): void {
    Channel::factory()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);

    Channel::factory()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);
})->throws(UniqueConstraintViolationException::class);

it('exposes its videos newest first', function (): void {
    $channel = Channel::factory()->create();
    Video::factory()->for($channel)->create(['published_at' => '2026-03-01 12:00:00']);
    $newest = Video::factory()->for($channel)->create(['published_at' => '2026-03-14 12:00:00']);

    expect($channel->latestVideo->id)->toBe($newest->id);
});

it('reports the last refresh as failed when an error is stored', function (): void {
    $failing = Channel::factory()->failing()->create();
    $healthy = Channel::factory()->create(['last_refresh_error' => null]);

    expect($failing->hasRefreshError())->toBeTrue()
        ->and($healthy->hasRefreshError())->toBeFalse();
});
