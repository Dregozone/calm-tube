<?php

use App\Models\Channel;
use App\Models\ReadingListItem;
use App\Models\Video;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
    configureReadingList();
    config()->set('calm-tube.refresh.retry_delay', 0);
});

function unfollowedVideo(): Video
{
    return Video::factory()
        ->for(Channel::factory()->unfollowed()->create(['youtube_channel_id' => READING_CHANNEL_ID]))
        ->create(['youtube_video_id' => READING_VIDEO_ID]);
}

it('plays a video from a channel you do not follow', function (): void {
    $video = unfollowedVideo();

    $this->get(route('videos.watch', $video))
        ->assertOk()
        ->assertSee('youtube-nocookie.com/embed/'.READING_VIDEO_ID, escape: false);
});

it('does not add the channel by playing its video', function (): void {
    $video = unfollowedVideo();

    Livewire::test('pages::watch', ['video' => $video])->call('markWatched');

    expect($video->channel->fresh())
        ->is_followed->toBeFalse()
        ->is_enabled->toBeFalse();
});

it('celebrates the end of a reading list video instead of counting down', function (): void {
    $video = unfollowedVideo();
    ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['title' => 'Watch the bridge one']);

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee('data-celebrate="1"', escape: false)
        ->assertSee("Nice one, that's one off the list!")
        ->assertSee('Watch the bridge one')
        ->assertSee('Mark as watched')
        ->assertSee('Not now');
});

it('counts down as before at the end of a feed video', function (): void {
    $video = Video::factory()->for(calmChannel())->create();

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee('data-celebrate="0"', escape: false)
        ->assertDontSee('one off the list')
        ->assertSee('Returning to your videos in');
});

it('does not celebrate a video already done on the list', function (): void {
    $video = unfollowedVideo();
    ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['percent_complete' => 90]);

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee('data-celebrate="0"', escape: false);
});

describe('marking it watched', function (): void {
    it('sends 90 percent to the right reading list item', function (): void {
        fakeReadingListProgress();
        ReadingListItem::factory()->create(['reading_list_id' => 7]);
        ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['reading_list_id' => 42]);

        Livewire::test('pages::watch', ['video' => unfollowedVideo()])
            ->call('markReadingListWatched')
            ->assertReturned(true);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === readingListEndpoint('/42')
            && $request['percent_complete'] === 90);
    });

    it('takes the card off the videos page', function (): void {
        fakeReadingListProgress();
        ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['title' => 'Watch the bridge one']);

        Livewire::test('pages::watch', ['video' => unfollowedVideo()])->call('markReadingListWatched');

        $this->get(route('videos.index'))->assertDontSee('Watch the bridge one');
    });

    it('keeps the card when Personal Assistant does not take it', function (): void {
        fakeReadingListProgress(500);
        ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['title' => 'Watch the bridge one']);

        Livewire::test('pages::watch', ['video' => unfollowedVideo()])
            ->call('markReadingListWatched')
            ->assertReturned(false);

        $this->get(route('videos.index'))->assertSee('Watch the bridge one');
    });

    it('keeps the card when Personal Assistant cannot be reached', function (): void {
        fakeReadingListDown();
        ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create(['title' => 'Watch the bridge one']);

        Livewire::test('pages::watch', ['video' => unfollowedVideo()])
            ->call('markReadingListWatched')
            ->assertReturned(false);

        $this->get(route('videos.index'))->assertSee('Watch the bridge one');
    });
});
