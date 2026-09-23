<?php

use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

it('shows the channel and how many videos it has', function (): void {
    $channel = calmChannel();
    Video::factory()->for($channel)->count(4)->create();
    Video::factory()->for($channel)->watched()->count(1)->create();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSee(CALM_CHANNEL_TITLE)
        ->assertSee('5 videos')
        ->assertSee('4 unwatched');
});

it('shows only this channel videos', function (): void {
    $channel = calmChannel();
    $mine = Video::factory()->for($channel)->create(['title' => 'Belongs To This Channel']);
    $theirs = Video::factory()->for(Channel::factory())->create(['title' => 'Belongs Elsewhere']);

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSee($mine->title)
        ->assertDontSee($theirs->title);
});

it('shows this channel videos newest first', function (): void {
    $channel = calmChannel();
    Video::factory()->for($channel)->create(['title' => 'Older', 'published_at' => '2026-03-01 12:00:00']);
    Video::factory()->for($channel)->create(['title' => 'Newer', 'published_at' => '2026-03-14 12:00:00']);

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSeeInOrder(['Newer', 'Older']);
});

/**
 * As on the feed, the filter is asserted through the view data rather than the
 * rendered cards: set() is an update request, and Livewire returns nested
 * components as empty shells because the browser keeps the existing DOM.
 */
it('filters this channel down to unwatched', function (): void {
    $channel = calmChannel();
    $unwatched = Video::factory()->for($channel)->create(['title' => 'Not Seen Yet']);
    Video::factory()->for($channel)->watched()->create(['title' => 'Already Seen']);

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->set('filter', 'unwatched')
        ->assertViewHas('videos', fn ($videos): bool => $videos->pluck('id')->all() === [$unwatched->id]);
});

it('paginates a long back catalogue', function (): void {
    $channel = calmChannel();
    Video::factory()->for($channel)->count(30)->create();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertViewHas('videos', fn ($videos): bool => $videos->count() === 24);
});

it('keeps Shorts off the channel page too', function (): void {
    $channel = calmChannel();
    $short = Video::factory()->for($channel)->short()->create(['title' => 'A Short You Do Not Want']);

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertDontSee($short->title);
});

it('refreshes just this channel', function (): void {
    Storage::fake('local');
    fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
    $channel = calmChannel();
    $other = Channel::factory()->create();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->call('refreshChannel');

    expect($channel->videos()->count())->toBe(1)
        ->and($other->videos()->count())->toBe(0);
});

it('links to the channel on YouTube', function (): void {
    $channel = calmChannel();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSee('https://www.youtube.com/channel/'.CALM_CHANNEL_ID, escape: false);
});

it('still shows the archive of a channel you have stopped following', function (): void {
    $channel = Channel::factory()->disabled()->create(['youtube_channel_id' => CALM_CHANNEL_ID]);
    $video = Video::factory()->for($channel)->create(['title' => 'Archived While Following']);

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSee($video->title);
});

it('offers a refresh when the channel has no videos yet', function (): void {
    Livewire::test('pages::channels.show', ['channel' => calmChannel()])
        ->assertSee('No videos yet');
});

it('shows the error when the last refresh failed', function (): void {
    $channel = Channel::factory()->failing()->create();

    Livewire::test('pages::channels.show', ['channel' => $channel])
        ->assertSee('failed');
});

it('returns 404 for a channel that is not followed', function (): void {
    $this->get(route('channels.show', 'UCnosuchchannelabcdefghi'))->assertNotFound();
});

it('redirects a guest to the login page', function (): void {
    $channel = calmChannel();
    auth()->logout();

    $this->get(route('channels.show', $channel))->assertRedirect(route('login'));
});

describe('clearing a channel', function (): void {
    it('marks every unwatched video from the channel in one action', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(6)->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])->call('markAllWatched');

        expect($channel->videos()->whereNull('watched_at')->count())->toBe(0);
    });

    it('reaches past the page you can see', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(30)->create();

        // The feed clears a page; a channel is a decision about the whole of
        // it, so all thirty go, not the twenty-four on screen.
        Livewire::test('pages::channels.show', ['channel' => $channel])->call('markAllWatched');

        expect($channel->videos()->whereNull('watched_at')->count())->toBe(0);
    });

    it('leaves other channels alone', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(2)->create();
        $theirs = Video::factory()->for(Channel::factory())->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])->call('markAllWatched');

        expect($theirs->fresh()->watched_at)->toBeNull();
    });

    it('can be taken back', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(4)->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])
            ->call('markAllWatched')
            ->call('undoBulk');

        expect($channel->videos()->whereNull('watched_at')->count())->toBe(4);
    });

    it('deletes nothing and hides nothing', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(3)->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])->call('markAllWatched');

        expect($channel->videos()->count())->toBe(3)
            ->and($channel->videos()->whereNotNull('hidden_at')->count())->toBe(0);
    });

    it('says so when there was nothing unwatched', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->watched()->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])
            ->call('markAllWatched')
            ->assertSee('Nothing here was unwatched');
    });

    it('updates the count in the header', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(3)->create();

        Livewire::test('pages::channels.show', ['channel' => $channel])
            ->call('markAllWatched')
            ->assertSee('0 unwatched');
    });
});
