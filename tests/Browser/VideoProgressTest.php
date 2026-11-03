<?php

use App\Models\Video;

/*
|--------------------------------------------------------------------------
| Where you stopped, in a real browser
|--------------------------------------------------------------------------
|
| A stand-in for YouTube's player that plays from wherever the test says,
| so leaving the page mid-video can be watched from the database.
|
*/

const FAKE_PLAYING_PLAYER_API = <<<'JS'
    window.YT = {
        PlayerState: { UNSTARTED: -1, ENDED: 0, PLAYING: 1, PAUSED: 2 },
        Player: function (element, options) {
            const player = this;
            player.state = -1;
            player.at = 0;
            player.getDuration = () => 600;
            player.getCurrentTime = () => player.at;
            player.getPlayerState = () => player.state;
            player.playVideo = () => {};
            player.pauseVideo = () => {};
            player.seekTo = () => {};
            player.setPlaybackRate = () => {};
            player.isMuted = () => false;
            window.calmPlayFrom = (seconds) => {
                player.at = seconds;
                player.state = 1;
                options.events.onStateChange({ data: 1 });
            };
            setTimeout(() => options.events.onReady({}), 0);
        },
    };
    window.onYouTubeIframeAPIReady && window.onYouTubeIframeAPIReady();
    JS;

beforeEach(function (): void {
    $this->actingAs(calmUser());

    config()->set('calm-tube.player.iframe_api_url', 'data:text/javascript,'.rawurlencode(FAKE_PLAYING_PLAYER_API));
    config()->set('calm-tube.player.autoplay', false);
});

function playingWatchPage(Video $video, int $seconds): mixed
{
    $page = visit(route('videos.watch', $video, absolute: false));
    $page->assertScript('typeof window.calmPlayFrom', 'function');
    $page->script("window.calmPlayFrom({$seconds})");

    return $page;
}

/**
 * The last save goes out as the next page swaps in, so it is given a moment
 * to land before anything reads the database.
 */
function leaveByBackLink(mixed $page): mixed
{
    $page->click('a:has-text("Back to feed")')->assertPathIs('/feed');

    return $page->wait(1);
}

it('saves where you were when you leave by the back link', function (): void {
    $video = Video::factory()->for(calmChannel())->create(['duration_seconds' => 600, 'resume_seconds' => 120]);

    leaveByBackLink(playingWatchPage($video, 300));

    expect($video->fresh()->resume_seconds)->toBe(300);
});

it('stops saving once you have left by the back link', function (): void {
    $video = Video::factory()->for(calmChannel())->create(['duration_seconds' => 600, 'resume_seconds' => 120]);

    $page = leaveByBackLink(playingWatchPage($video, 300));

    // What a later visit saved; the page left behind must not undo it.
    $video->forceFill(['resume_seconds' => 450])->save();
    $page->wait(11);

    expect($video->fresh()->resume_seconds)->toBe(450);
});
