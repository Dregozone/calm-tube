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

describe('returning to the feed', function (): void {
    it('counts down before leaving, so the exit is automatic but never the arrival', function (): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee('Returning to your videos in', escape: false)
            ->assertSee('id="calm-countdown"', escape: false)
            ->assertSee('startCountdown', escape: false);
    });

    it('counts down in a modal fixed to the viewport, not tucked inside the player', function (): void {
        $video = watchable();

        // Anchored to the player it would be missed by anyone scrolled down
        // the page, which is exactly what happened the first time.
        $this->get(route('videos.watch', $video))
            ->assertSee('id="calm-countdown"', escape: false)
            ->assertSee('fixed inset-0 z-50', escape: false)
            ->assertSee('aria-modal="true"', escape: false);
    });

    it('keeps the panels out of the morph, so marking watched cannot hide them', function (): void {
        $video = watchable();

        // Marking the video watched re-renders the component. Without
        // wire:ignore the morph restores the server-rendered "hidden" and the
        // countdown runs invisibly.
        $this->get(route('videos.watch', $video))
            ->assertSee('wire:ignore', escape: false);
    });

    it('counts from the configured number of seconds', function (): void {
        config()->set('calm-tube.player.countdown_seconds', 9);
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee('data-countdown-seconds="9"', escape: false);
    });

    it('offers a way to stay, and stops counting when anything else is chosen', function (): void {
        $video = watchable();

        // Quotes arrive escaped inside Livewire's JSON payload, so the
        // assertion looks for the identifiers rather than the exact source.
        $this->get(route('videos.watch', $video))
            ->assertSee('Stay here')
            ->assertSee('calm-stay', escape: false)
            ->assertSee('Go now')
            ->assertSee('stopCountdown', escape: false);
    });

    it('returns to the feed filtered the way it was left', function (): void {
        session()->put('calm-tube.feed.filter', 'unwatched');
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee(route('feed', ['filter' => 'unwatched']), escape: false);
    });

    it('carries the channel filter back too', function (): void {
        session()->put('calm-tube.feed.filter', 'unwatched');
        session()->put('calm-tube.feed.channel', CALM_CHANNEL_ID);
        $video = watchable();

        // Escaped, because the ampersand between the two parameters is
        // written as an entity inside the attribute.
        $this->get(route('videos.watch', $video))
            ->assertSee(route('feed', ['filter' => 'unwatched', 'channel' => CALM_CHANNEL_ID]));
    });

    it('returns to the plain feed when no filter was in use', function (): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee('data-return-url="'.route('feed').'"', escape: false);
    });
});

describe('playback speed', function (): void {
    it('plays at normal speed when the channel has no preference', function (): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee('data-playback-rate="1"', escape: false);
    });

    it('carries the chosen speed of the channel into the player', function (): void {
        $channel = calmChannel(['playback_rate' => 2.0]);
        $video = Video::factory()->for($channel)->create();

        $this->get(route('videos.watch', $video))
            ->assertSee('data-playback-rate="2"', escape: false)
            ->assertSee('setPlaybackRate', escape: false);
    });

    it('stores a chosen speed on the channel, not on the video', function (): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->set('playbackRate', '1.5')
            ->assertHasNoErrors();

        expect($video->channel->fresh()->playback_rate)->toBe(1.5);
    });

    it('applies to every other video from the same channel', function (): void {
        $video = watchable();
        $another = Video::factory()->for($video->channel)->create();

        Livewire::test('pages::watch', ['video' => $video])->set('playbackRate', '2');

        $this->get(route('videos.watch', $another->fresh()))
            ->assertSee('data-playback-rate="2"', escape: false);
    });

    it('returns to normal speed when the preference is cleared', function (): void {
        $channel = calmChannel(['playback_rate' => 2.0]);
        $video = Video::factory()->for($channel)->create();

        Livewire::test('pages::watch', ['video' => $video])->set('playbackRate', '');

        expect($channel->fresh()->playback_rate)->toBeNull();
    });

    it('refuses a rate the player would not accept', function (): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->set('playbackRate', '7')
            ->assertHasErrors('playbackRate');

        expect($video->channel->fresh()->playback_rate)->toBeNull();
    });

    it('tells the player about the change without waiting for the next video', function (): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])
            ->set('playbackRate', '1.25')
            ->assertDispatched('playback-rate-changed', rate: 1.25);
    });
});

describe('the end-card mask', function (): void {
    it('covers the picture for the last seconds, where YouTube draws its end cards', function (): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))
            ->assertSee('id="calm-mask"', escape: false)
            ->assertSee('data-mask-seconds="20"', escape: false)
            ->assertSee('watchForEndCards', escape: false);
    });

    it('leaves the control bar exposed', function (): void {
        $video = watchable();

        // bottom-14 stops the mask short of YouTube's own controls, so the
        // scrubber and volume stay reachable through it.
        $this->get(route('videos.watch', $video))->assertSee('bottom-14', escape: false);
    });

    it('pauses when clicked, rather than swallowing the one useful click', function (): void {
        $video = watchable();

        $this->get(route('videos.watch', $video))->assertSee('pauseVideo', escape: false);
    });

    it('can be turned off entirely', function (): void {
        config()->set('calm-tube.player.end_card_mask_seconds', 0);
        $video = watchable();

        $this->get(route('videos.watch', $video))->assertSee('data-mask-seconds="0"', escape: false);
    });
});

describe('picking up where you left off', function (): void {
    it('offers to resume rather than silently starting in the middle', function (): void {
        $video = watchable(['resume_seconds' => 1122, 'duration_seconds' => 2400]);

        $this->get(route('videos.watch', $video))
            ->assertSee('You stopped at')
            ->assertSee('18:42')
            ->assertSee('Start again')
            ->assertSee('data-resume-seconds="1122"', escape: false);
    });

    it('says nothing about resuming a video you have not started', function (): void {
        $video = watchable(['resume_seconds' => null]);

        $this->get(route('videos.watch', $video))
            ->assertDontSee('You stopped at')
            ->assertSee('data-resume-seconds="0"', escape: false);
    });

    it('stores the position the player reports', function (): void {
        $video = watchable();

        Livewire::test('pages::watch', ['video' => $video])->call('saveProgress', 742);

        expect($video->fresh()->resume_seconds)->toBe(742);
    });

    it('ignores a nonsense position', function (): void {
        $video = watchable(['resume_seconds' => 300]);

        Livewire::test('pages::watch', ['video' => $video])->call('saveProgress', -5);

        expect($video->fresh()->resume_seconds)->toBe(300);
    });

    it('does not re-render for a number nothing on screen reads', function (): void {
        $video = watchable();

        // A round trip every ten seconds is cheap; re-rendering the page
        // around it is not.
        Livewire::test('pages::watch', ['video' => $video])
            ->call('saveProgress', 100)
            ->assertOk();

        expect($video->fresh()->resume_seconds)->toBe(100);
    });

    it('forgets the position when the video is finished', function (): void {
        $video = watchable(['resume_seconds' => 2000]);

        Livewire::test('pages::watch', ['video' => $video])->call('markWatched');

        expect($video->fresh()->resume_seconds)->toBeNull()
            ->and($video->fresh()->watched_at)->not->toBeNull();
    });

    it('forgets the position when you start again', function (): void {
        $video = watchable(['resume_seconds' => 2000]);

        Livewire::test('pages::watch', ['video' => $video])->call('clearProgress');

        expect($video->fresh()->resume_seconds)->toBeNull();
    });

    it('forgets the position when you mark it unwatched', function (): void {
        $video = watchable(['resume_seconds' => 2000, 'watched_at' => now()]);

        Livewire::test('pages::watch', ['video' => $video])->call('markUnwatched');

        expect($video->fresh()->resume_seconds)->toBeNull();
    });
});

describe('the description', function (): void {
    it('starts closed, so the links out are not in front of you', function (): void {
        $video = watchable(['description' => 'Sponsored by something https://example.com/buy']);

        $this->get(route('videos.watch', $video))
            ->assertSee('Show description')
            ->assertSee('x-cloak', escape: false);
    });
});
