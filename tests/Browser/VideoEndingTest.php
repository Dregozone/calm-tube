<?php

use App\Models\Channel;
use App\Models\ReadingListItem;
use App\Models\Video;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The end of a video, in a real browser
|--------------------------------------------------------------------------
|
| YouTube's player cannot be told to finish on cue, so the page loads a
| stand-in for its API script instead: a player that reports what the watch
| page asks of it and ends when the test says so.
|
*/

const FAKE_PLAYER_API = <<<'JS'
    window.YT = {
        PlayerState: { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2 },
        Player: function (element, options) {
            const player = this;
            player.state = -1;
            player.getDuration = () => 600;
            player.getCurrentTime = () => (player.state === 0 ? 600 : 0);
            player.getPlayerState = () => player.state;
            player.playVideo = () => {};
            player.pauseVideo = () => {};
            player.seekTo = () => {};
            player.setPlaybackRate = () => {};
            player.isMuted = () => false;
            window.calmEndVideo = () => {
                player.state = 0;
                options.events.onStateChange({ data: 0 });
            };
            setTimeout(() => options.events.onReady({}), 0);
        },
    };
    window.onYouTubeIframeAPIReady && window.onYouTubeIframeAPIReady();
    JS;

beforeEach(function (): void {
    $this->actingAs(calmUser());
    configureReadingList();

    config()->set('calm-tube.player.iframe_api_url', 'data:text/javascript,'.rawurlencode(FAKE_PLAYER_API));
    config()->set('calm-tube.player.autoplay', false);
    // Long enough that the countdown cannot leave the page mid-test.
    config()->set('calm-tube.player.countdown_seconds', 60);
});

function readingListWatchPage(): mixed
{
    $video = Video::factory()
        ->for(Channel::factory()->unfollowed()->create())
        ->create(['youtube_video_id' => READING_VIDEO_ID, 'duration_seconds' => 600]);

    ReadingListItem::factory()->forVideo(READING_VIDEO_ID)->create([
        'reading_list_id' => 42,
        'title' => 'Watch the bridge one',
    ]);

    $page = visit(route('videos.watch', $video, absolute: false));
    $page->assertScript('typeof window.calmEndVideo', 'function');

    return $page;
}

it('celebrates when a reading list video ends', function (): void {
    $page = readingListWatchPage();

    $page->script('window.calmEndVideo()');

    $page->assertVisible('#calm-celebrate')
        ->assertSee("Nice one, that's one off the list!")
        ->assertSee('Watch the bridge one')
        ->assertMissing('#calm-countdown');
});

it('still counts down when a feed video ends', function (): void {
    $video = Video::factory()->for(calmChannel())->create(['duration_seconds' => 600]);

    $page = visit(route('videos.watch', $video, absolute: false));
    $page->assertScript('typeof window.calmEndVideo', 'function');

    $page->script('window.calmEndVideo()');

    $page->assertVisible('#calm-countdown')
        ->assertSee('Returning to your videos in')
        ->assertNotPresent('#calm-celebrate');
});

it('confirms when Personal Assistant takes the video off the list', function (): void {
    fakeReadingListProgress();
    $page = readingListWatchPage();

    $page->script('window.calmEndVideo()');
    $page->click('#calm-celebrate-done')
        ->assertSee("Marked as watched. It's off your videos page.");

    Http::assertSent(fn (Request $request): bool => $request->url() === readingListEndpoint('/42')
        && $request['percent_complete'] === 90);
});

it('says so when Personal Assistant does not take it', function (): void {
    fakeReadingListProgress(500);
    $page = readingListWatchPage();

    $page->script('window.calmEndVideo()');
    $page->click('#calm-celebrate-done')
        ->assertSee("Personal Assistant didn't take it, so it's still on your list.");

    expect(ReadingListItem::query()->sole()->percent_complete)->toBe(0);
});

it('closes the modal on Not now', function (): void {
    $page = readingListWatchPage();

    $page->script('window.calmEndVideo()');
    $page->click('#calm-celebrate-later')
        ->assertMissing('#calm-celebrate');
});
