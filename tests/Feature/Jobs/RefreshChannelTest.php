<?php

use App\Enums\RefreshTrigger;
use App\Jobs\RefreshChannel;
use App\Models\RefreshRun;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;

it('refreshes the channel it was given', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
    $channel = calmChannel();

    RefreshChannel::dispatchSync($channel);

    expect($channel->videos()->count())->toBe(1);
});

it('passes the trigger through to the refresh run', function (): void {
    Storage::fake('local');
    fakeFeed('feed-empty.xml');
    $channel = calmChannel();

    RefreshChannel::dispatchSync($channel, RefreshTrigger::Scheduled);

    expect(RefreshRun::sole()->trigger)->toBe(RefreshTrigger::Scheduled);
});

it('does not throw when the refresh fails, so a run can continue', function (): void {
    fakeFeedFailure();
    $channel = calmChannel();

    RefreshChannel::dispatchSync($channel);

    expect(Video::count())->toBe(0)
        ->and($channel->fresh()->last_refresh_error)->not->toBeNull();
});
