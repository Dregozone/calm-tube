<?php

use App\Models\Channel;
use App\Models\Video;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

function watchable(array $attributes = []): Video
{
    return Video::factory()->for(calmChannel())->create([
        'youtube_video_id' => CALM_VIDEO_ID,
        ...$attributes,
    ]);
}

it('shows the archived title, not whatever YouTube calls it now', function (): void {
    $video = watchable(['title' => 'The Title I Archived']);

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee('The Title I Archived');
});

it('shows the channel, publication date and duration', function (): void {
    $this->travelTo('2026-03-18 12:00:00');
    $video = watchable(['published_at' => '2026-03-15 12:00:00', 'duration_seconds' => 724]);

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee(CALM_CHANNEL_TITLE)
        ->assertSee('3 days ago')
        ->assertSee('12:04');
});

it('omits the duration when it is unknown', function (): void {
    $video = watchable(['duration_seconds' => null]);

    Livewire::test('pages::watch', ['video' => $video])->assertOk();
});

describe('the embedded player', function (): void {
    it('uses the privacy-preserving embed host', function (): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->assertSee('youtube-nocookie.com/embed/'.CALM_VIDEO_ID, escape: false);
    });

    it('sets the player parameters that keep it calm', function (string $parameter): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->assertSee($parameter, escape: false);
    })->with([
        'no related videos' => ['rel=0'],
        'no annotations' => ['iv_load_policy=3'],
        'no autoplay' => ['autoplay=0'],
        'the JS API, for detecting the end of the video' => ['enablejsapi=1'],
    ]);

    it('never asks the player to autoplay or loop', function (string $parameter): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->assertDontSee($parameter, escape: false);
    })->with([
        'autoplay' => ['autoplay=1'],
        'loop' => ['loop=1'],
    ]);
});

it('links to the original video on YouTube in a new tab', function (): void {
    $video = watchable();

    Livewire::test('pages::watch', ['video' => $video])
        ->assertSee('https://www.youtube.com/watch?v='.CALM_VIDEO_ID, escape: false)
        ->assertSee('rel="noopener noreferrer"', escape: false);
});

describe('marking as watched', function (): void {
    it('marks the video watched when the player reports the end', function (): void {
        $this->freezeTime();
        $video = watchable(['watched_at' => null]);

        Livewire::test('pages::watch', ['video' => $video])->call('markWatched');

        expect($video->fresh()->watched_at->timestamp)->toBe(now()->timestamp);
    });

    it('marks the video unwatched again', function (): void {
        $video = Video::factory()->for(calmChannel())->watched()->create();

        Livewire::test('pages::watch', ['video' => $video])->call('markUnwatched');

        expect($video->fresh()->watched_at)->toBeNull();
    });

    it('offers to mark a watched video as unwatched', function (): void {
        $video = Video::factory()->for(calmChannel())->watched()->create();

        Livewire::test('pages::watch', ['video' => $video])->assertSee('unwatched');
    });
});

describe('the end-of-video panel', function (): void {
    it('offers the next unwatched video from the same channel', function (): void {
        $channel = calmChannel();
        $watching = Video::factory()->for($channel)->create(['published_at' => '2026-03-14 12:00:00']);
        $next = Video::factory()->for($channel)->create([
            'title' => 'The Next One I Have Not Seen',
            'published_at' => '2026-03-13 12:00:00',
        ]);

        Livewire::test('pages::watch', ['video' => $watching])
            ->assertSee($next->title);
    });

    it('offers nothing when everything else on the channel is watched', function (): void {
        $channel = calmChannel();
        $watching = Video::factory()->for($channel)->create();
        $seen = Video::factory()->for($channel)->watched()->create(['title' => 'Already Seen This']);

        Livewire::test('pages::watch', ['video' => $watching])
            ->assertDontSee($seen->title);
    });

    it('does not offer a video from a different channel', function (): void {
        $watching = Video::factory()->for(calmChannel())->create();
        $elsewhere = Video::factory()->for(Channel::factory())->create([
            'title' => 'Something From Somewhere Else',
        ]);

        Livewire::test('pages::watch', ['video' => $watching])
            ->assertDontSee($elsewhere->title);
    });
});

describe('the description', function (): void {
    it('turns a bare url into a link', function (): void {
        $video = watchable(['description' => 'Notes at https://example.com/paper.pdf for reference.']);

        Livewire::test('pages::watch', ['video' => $video])
            ->assertSee('href="https://example.com/paper.pdf"', escape: false);
    });

    it('escapes html in the description', function (): void {
        $video = watchable(['description' => 'Watch out <script>alert("xss")</script> here.']);

        Livewire::test('pages::watch', ['video' => $video])
            ->assertDontSee('<script>alert', escape: false);
    });

    it('handles a video with no description', function (): void {
        $video = watchable(['description' => null]);

        Livewire::test('pages::watch', ['video' => $video])->assertOk();
    });
});

describe('videos that will not play', function (): void {
    it('still shows the archive of a video YouTube has removed', function (): void {
        $video = Video::factory()->for(calmChannel())->unavailable()->create([
            'title' => 'A Video That Is Gone Now',
        ]);

        Livewire::test('pages::watch', ['video' => $video])
            ->assertSee('A Video That Is Gone Now')
            ->assertSee('no longer available');
    });

    it('records that a video has gone when the player reports it missing', function (): void {
        $this->freezeTime();
        $video = watchable(['unavailable_at' => null]);

        Livewire::test('pages::watch', ['video' => $video])->call('markUnavailable');

        expect($video->fresh()->unavailable_at->timestamp)->toBe(now()->timestamp);
    });

    it('notes when a video is scheduled rather than published', function (): void {
        $video = Video::factory()->for(calmChannel())->upcoming()->create([
            'scheduled_start_at' => '2026-03-20 19:30:00',
        ]);

        Livewire::test('pages::watch', ['video' => $video])->assertOk();
    });
});

it('returns 404 for a video that is not in the library', function (): void {
    $this->get(route('videos.watch', 'calmvid9999'))->assertNotFound();
});

it('redirects a guest to the login page', function (): void {
    $video = watchable();
    auth()->logout();

    $this->get(route('videos.watch', $video))->assertRedirect(route('login'));
});

describe('the player script', function (): void {
    it('delivers the IFrame API wiring, without which the end of a video is never noticed', function (string $needle): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))->assertSee($needle, escape: false);
    })->with([
        // Slashes arrive escaped, the script riding in Livewire's JSON payload.
        'the API script' => ['iframe_api'],
        'the ready callback' => ['onYouTubeIframeAPIReady'],
        'the ended state' => ['YT.PlayerState.ENDED'],
        'marking watched from the player' => ['markWatched'],
    ]);

    it('binds to the iframe already in the page rather than building one', function (): void {
        $video = watchable();

        // The iframe is server rendered so the embed parameters are fixed and
        // testable; the API attaches to it by id.
        $this->get(route('videos.watch', $video))
            ->assertSee('id="calm-player"', escape: false)
            ->assertSee('new YT.Player(frame', escape: false);
    });
});
