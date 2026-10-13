<?php

use App\Models\ReadingListItem;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

beforeEach(function (): void {
    configureReadingList();
    config()->set('calm-tube.refresh.retry_delay', 0);
});

it('fetches the reading list and says how many videos are on it', function (): void {
    Video::factory()->for(calmChannel())->create(['youtube_video_id' => CALM_VIDEO_ID]);
    fakeReadingList([
        readingListItem(1, 'https://youtu.be/'.CALM_VIDEO_ID),
        readingListItem(2, 'https://example.com/article'),
    ]);

    $this->artisan('calm:reading-list')
        ->expectsOutput('1 video to watch on the reading list.')
        ->assertSuccessful();

    expect(ReadingListItem::query()->count())->toBeOne();
});

it('fails, keeping the list it has, when Personal Assistant is down', function (): void {
    ReadingListItem::factory()->create();
    fakeReadingListDown();

    $this->artisan('calm:reading-list')->assertFailed();

    expect(ReadingListItem::query()->count())->toBeOne();
});

it('says what to set when Personal Assistant is not set up', function (): void {
    config()->set('services.reading_list.url');

    $this->artisan('calm:reading-list')
        ->expectsOutputToContain('READING_LIST_URL')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('is scheduled once a day', function (): void {
    $events = collect(Schedule::events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'calm:reading-list'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 5 * * *');
});
