<?php

use App\Models\Channel;
use App\Models\ReadingListItem;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
    configureReadingList();
    config()->set('calm-tube.refresh.retry_delay', 0);
});

/**
 * A reading list item whose video is already archived.
 *
 * @param  array<string, mixed>  $item
 * @param  array<string, mixed>  $video
 */
function readingListVideo(array $item = [], array $video = []): ReadingListItem
{
    $archived = Video::factory()
        ->for(Channel::factory()->unfollowed()->create(['title' => 'Quiet Rooms']))
        ->create(['duration_seconds' => 1122, ...$video]);

    return ReadingListItem::factory()->forVideo($archived->youtube_video_id)->create($item);
}

it('is in the navigation', function (): void {
    $this->get(route('feed'))->assertSee(route('videos.index'));
});

it('shows each video with the reading list title, its category and progress', function (): void {
    readingListVideo(
        ['title' => 'Watch the bridge one', 'category' => 'Engineering', 'percent_complete' => 40],
        ['title' => 'How Bridges Hold Up'],
    );

    $this->get(route('videos.index'))
        ->assertOk()
        ->assertSee('Watch the bridge one')
        ->assertDontSee('How Bridges Hold Up')
        ->assertSee('Engineering')
        ->assertSee('40%')
        ->assertSee('18:42')
        ->assertSee('Quiet Rooms');
});

it('plays each video in Calm Tube', function (): void {
    $item = readingListVideo();

    $this->get(route('videos.index'))
        ->assertSee(route('videos.watch', [$item->youtube_video_id, 'from' => 'videos']));
});

it('keeps the reading list order', function (): void {
    readingListVideo(['title' => 'Second', 'position' => 1]);
    readingListVideo(['title' => 'First', 'position' => 0]);

    $this->get(route('videos.index'))->assertSeeInOrder(['First', 'Second']);
});

it('leaves out videos that are done', function (int $percent): void {
    readingListVideo(['title' => 'Nearly done', 'percent_complete' => $percent]);

    $this->get(route('videos.index'))->assertDontSee('Nearly done');
})->with([90, 95, 100]);

it('leaves out a video that turned out to be a Short', function (): void {
    readingListVideo(['title' => 'Secretly a Short'], ['is_short' => true]);

    $this->get(route('videos.index'))->assertDontSee('Secretly a Short');
});

it('offers YouTube for a video not archived yet', function (): void {
    ReadingListItem::factory()->create(['title' => 'Waiting for a key', 'url' => 'https://youtu.be/abcDEF12345']);

    $this->get(route('videos.index'))
        ->assertSee('Waiting for a key')
        ->assertSee('Not archived yet')
        ->assertSee('https://youtu.be/abcDEF12345');
});

describe('refreshing', function (): void {
    it('updates the list when asked', function (): void {
        readingListVideo(['reading_list_id' => 1, 'title' => 'Old title']);
        $videoId = ReadingListItem::query()->sole()->youtube_video_id;
        fakeReadingList([
            readingListItem(1, 'https://youtu.be/'.$videoId, ['title' => 'New title']),
        ]);

        Livewire::test('pages::videos')
            ->call('refresh')
            ->assertSee('New title')
            ->assertDontSee('Old title')
            ->assertSee('Reading list refreshed.');
    });

    it('shows the list it has when Personal Assistant is down', function (): void {
        readingListVideo(['title' => 'Kept from last time']);
        fakeReadingListDown();

        Livewire::test('pages::videos')
            ->call('refresh')
            ->assertSee('Kept from last time')
            ->assertSee("Couldn't reach Personal Assistant");
    });

    it('says when the list was last updated', function (): void {
        $this->travelTo('2026-10-13 09:00:00');
        fakeReadingList([]);
        Livewire::test('pages::videos')->call('refresh');

        $this->travel(3)->hours();

        Livewire::test('pages::videos')->assertSee('Last updated 3 hours ago');
    });

    it('refreshes by itself when the list is more than a day old', function (): void {
        fakeReadingList([]);

        Livewire::test('pages::videos')->call('autoRefresh');

        Http::assertSentCount(1);
    });

    it('leaves a list updated today alone', function (): void {
        fakeReadingList([]);
        Livewire::test('pages::videos')->call('refresh');

        $this->travel(23)->hours();
        Livewire::test('pages::videos')->call('autoRefresh');

        Http::assertSentCount(1);
    });
});

it('explains what is missing before Personal Assistant is set up', function (): void {
    config()->set('services.reading_list.url');

    Livewire::test('pages::videos')
        ->assertSee('Personal Assistant is not set up')
        ->assertDontSee('Refresh')
        ->call('autoRefresh');

    Http::assertNothingSent();
});

it('sends a video opened here back here', function (): void {
    $item = readingListVideo();

    $this->get(route('videos.watch', [$item->youtube_video_id, 'from' => 'videos']))
        ->assertSee('Back to videos')
        ->assertSee('data-return-url="'.route('videos.index').'"', escape: false);
});

it('sends a video opened anywhere else back to the feed', function (): void {
    $item = readingListVideo();

    $this->get(route('videos.watch', $item->youtube_video_id))
        ->assertSee('Back to feed')
        ->assertSee('data-return-url="'.route('feed').'"', escape: false);
});
