<?php

use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

it('shows videos newest first', function (): void {
    $channel = calmChannel();
    Video::factory()->for($channel)->create(['title' => 'Older', 'published_at' => '2026-03-01 12:00:00']);
    Video::factory()->for($channel)->create(['title' => 'Newer', 'published_at' => '2026-03-14 12:00:00']);

    Livewire::test('pages::feed')->assertSeeInOrder(['Newer', 'Older']);
});

it('shows the channel name and archived title on each card', function (): void {
    $video = Video::factory()->for(calmChannel(['custom_name' => 'Bridges Guy']))->create([
        'title' => 'How Arch Bridges Actually Work',
    ]);

    Livewire::test('pages::feed')
        ->assertSee($video->title)
        ->assertSee('Bridges Guy');
});

it('does not show a Short', function (): void {
    $short = Video::factory()->for(calmChannel())->short()->create(['title' => 'A Short You Do Not Want']);

    Livewire::test('pages::feed')->assertDontSee($short->title);
});

it('does not offer a way to show Shorts', function (): void {
    Video::factory()->for(calmChannel())->short()->create();

    Livewire::test('pages::feed')->assertDontSee('Shorts', escape: false);
});

describe('pagination', function (): void {
    it('shows one page of videos at a time', function (): void {
        Video::factory()->for(calmChannel())->count(30)->create();

        Livewire::test('pages::feed')->assertViewHas('videos', fn ($videos): bool => $videos->count() === 24);
    });

    it('shows the rest on the next page', function (): void {
        Video::factory()->for(calmChannel())->count(30)->create();

        Livewire::test('pages::feed')
            ->call('nextPage')
            ->assertViewHas('videos', fn ($videos): bool => $videos->count() === 6);
    });

    it('hides the pager when everything fits on one page', function (): void {
        Video::factory()->for(calmChannel())->count(3)->create();

        Livewire::test('pages::feed')->assertDontSee('nextPage', escape: false);
    });

    it('offers the pager when there is more than one page', function (): void {
        Video::factory()->for(calmChannel())->count(30)->create();

        Livewire::test('pages::feed')->assertSee('nextPage', escape: false);
    });

    it('uses the configured page size', function (): void {
        config()->set('calm-tube.feed.per_page', 5);
        Video::factory()->for(calmChannel())->count(8)->create();

        Livewire::test('pages::feed')->assertViewHas('videos', fn ($videos): bool => $videos->count() === 5);
    });
});

describe('filters', function (): void {
    /*
     * These assert the paginated set rather than the rendered cards. A card is
     * a nested Livewire component, and on an update request Livewire renders
     * children as empty shells because the browser keeps the existing DOM, so
     * asserting on card markup after set() would test Livewire's diffing
     * rather than the filter.
     */

    it('shows only unwatched videos when asked', function (): void {
        $channel = calmChannel();
        $unwatched = Video::factory()->for($channel)->create(['title' => 'Not Seen Yet']);
        Video::factory()->for($channel)->watched()->create(['title' => 'Already Seen']);

        Livewire::test('pages::feed')
            ->set('filter', 'unwatched')
            ->assertViewHas('videos', fn ($videos): bool => $videos->pluck('id')->all() === [$unwatched->id]);
    });

    it('shows only the selected channel', function (): void {
        $wanted = Video::factory()->for(calmChannel())->create(['title' => 'From The Channel I Picked']);
        Video::factory()->for(Channel::factory())->create(['title' => 'From Another Channel']);

        Livewire::test('pages::feed')
            ->set('channel', $wanted->channel->youtube_channel_id)
            ->assertViewHas('videos', fn ($videos): bool => $videos->pluck('id')->all() === [$wanted->id]);
    });

    it('keeps the filters in the url so they survive a reload', function (): void {
        Livewire::withQueryParams(['filter' => 'unwatched'])
            ->test('pages::feed')
            ->assertSet('filter', 'unwatched');
    });

    it('returns to the first page when a filter changes', function (): void {
        Video::factory()->for(calmChannel())->count(30)->create();

        Livewire::test('pages::feed')
            ->call('nextPage')
            ->set('filter', 'unwatched')
            ->assertSet('paginators.page', 1);
    });
});

describe('empty states', function (): void {
    it('invites you to add a channel when you follow none', function (): void {
        Livewire::test('pages::feed')
            ->assertSee('Add a channel')
            ->assertSee('Nothing here yet');
    });

    it('offers a refresh when channels have no videos yet', function (): void {
        calmChannel();

        Livewire::test('pages::feed')->assertSee('No videos yet');
    });

    it('says you are caught up when nothing is unwatched', function (): void {
        Video::factory()->for(calmChannel())->watched()->create();

        Livewire::test('pages::feed')
            ->set('filter', 'unwatched')
            ->assertSee('caught up');
    });
});

describe('refreshing', function (): void {
    it('refreshes every enabled channel', function (): void {
        Bus::fake([RefreshChannel::class]);
        calmChannel();
        Channel::factory()->create();
        Channel::factory()->disabled()->create();

        Livewire::test('pages::feed')->call('refreshAll');

        Bus::assertDispatchedSyncTimes(RefreshChannel::class, 2);
    });

    it('reports how many new videos arrived', function (): void {
        Storage::fake('local');
        fakeSuccessfulRefresh('feed-single-entry.xml', 'videos.list-single.json');
        calmChannel();

        Livewire::test('pages::feed')
            ->call('refreshAll')
            ->assertSee('1 new video');
    });

    it('says so when nothing new arrived', function (): void {
        Storage::fake('local');
        fakeFeed('feed-empty.xml');
        calmChannel();

        Livewire::test('pages::feed')
            ->call('refreshAll')
            ->assertSee('No new videos');
    });

    it('reports a channel that failed without hiding the ones that worked', function (): void {
        Storage::fake('local');
        // No key, so the unreachable feed has no uploads playlist to fall back to.
        config()->set('calm-tube.api_key');
        fakeFeedFailure();
        calmChannel();

        Livewire::test('pages::feed')
            ->call('refreshAll')
            ->assertSee('failed');
    });

    it('shows when the feed was last refreshed', function (): void {
        $this->travelTo('2026-03-15 12:00:00');
        calmChannel(['last_refreshed_at' => now()->subMinutes(14)]);

        Livewire::test('pages::feed')->assertSee('14 minutes ago');
    });
});

describe('degraded mode', function (): void {
    it('warns when no API key is configured', function (): void {
        config()->set('calm-tube.api_key');
        Video::factory()->for(calmChannel())->create();

        Livewire::test('pages::feed')->assertSee('API key');
    });

    it('stays quiet when an API key is configured', function (): void {
        Video::factory()->for(calmChannel())->create();

        Livewire::test('pages::feed')->assertDontSee('API key');
    });
});

it('loads each card without querying per video', function (): void {
    Video::factory()->for(calmChannel())->count(24)->create();

    DB::enableQueryLog();
    Livewire::test('pages::feed');
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThan(10);
});

it('redirects a guest to the login page', function (): void {
    auth()->logout();

    $this->get(route('feed'))->assertRedirect(route('login'));
});

describe('remembering the filter', function (): void {
    it('picks up the filter it was last left on', function (): void {
        session()->put('calm-tube.feed.filter', 'unwatched');

        Livewire::withQueryParams([])
            ->test('pages::feed')
            ->assertSet('filter', 'unwatched');
    });

    it('lets the url win over what was remembered', function (): void {
        session()->put('calm-tube.feed.filter', 'unwatched');

        Livewire::withQueryParams(['filter' => 'all'])
            ->test('pages::feed')
            ->assertSet('filter', 'all');
    });

    it('remembers a filter as soon as it changes', function (): void {
        Livewire::test('pages::feed')->set('filter', 'unwatched');

        expect(session('calm-tube.feed.filter'))->toBe('unwatched');
    });

    it('remembers the channel filter too', function (): void {
        $channel = calmChannel();

        Livewire::test('pages::feed')->set('channel', $channel->youtube_channel_id);

        expect(session('calm-tube.feed.channel'))->toBe($channel->youtube_channel_id);
    });
});

describe('clearing a page', function (): void {
    it('marks everything on the page as watched in one action', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(5)->create();

        Livewire::test('pages::feed')->call('markPageWatched');

        expect(Video::query()->whereNull('watched_at')->count())->toBe(0);
    });

    it('only touches the page in front of you', function (): void {
        $channel = calmChannel();
        config()->set('calm-tube.feed.per_page', 3);
        Video::factory()->for($channel)->count(8)->create();

        Livewire::test('pages::feed')->call('markPageWatched');

        expect(Video::query()->whereNull('watched_at')->count())->toBe(5);
    });

    it('can be taken back', function (): void {
        $channel = calmChannel();
        Video::factory()->for($channel)->count(4)->create();

        Livewire::test('pages::feed')
            ->call('markPageWatched')
            ->call('undoBulk');

        expect(Video::query()->whereNull('watched_at')->count())->toBe(4);
    });

    it('puts back only what it marked, never what you had already watched', function (): void {
        $channel = calmChannel();
        $already = Video::factory()->for($channel)->watched()->create();
        Video::factory()->for($channel)->count(2)->create();

        Livewire::test('pages::feed')
            ->call('markPageWatched')
            ->call('undoBulk');

        expect($already->fresh()->watched_at)->not->toBeNull()
            ->and(Video::query()->whereNull('watched_at')->count())->toBe(2);
    });

    it('clears any position stored in the videos it marks', function (): void {
        $video = Video::factory()->for(calmChannel())->partlyWatched()->create();

        Livewire::test('pages::feed')->call('markPageWatched');

        expect($video->fresh()->resume_seconds)->toBeNull();
    });

    it('says so when there was nothing to clear', function (): void {
        Video::factory()->for(calmChannel())->watched()->count(2)->create();

        Livewire::test('pages::feed')
            ->set('filter', 'all')
            ->call('markPageWatched')
            ->assertSee('already watched');
    });

    it('reports how many it marked', function (): void {
        Video::factory()->for(calmChannel())->count(3)->create();

        Livewire::test('pages::feed')->call('markPageWatched')->assertSee('3 videos marked as watched');
    });
});

describe('the default filter', function (): void {
    it('opens on what you have not seen', function (): void {
        Livewire::test('pages::feed')->assertSet('filter', 'unwatched');
    });

    it('takes the default from configuration', function (): void {
        config()->set('calm-tube.feed.default_filter', 'all');

        Livewire::test('pages::feed')->assertSet('filter', 'all');
    });

    it('still prefers whatever you last chose', function (): void {
        session()->put('calm-tube.feed.filter', 'all');

        Livewire::test('pages::feed')->assertSet('filter', 'all');
    });

    it('asks you to refresh rather than congratulating you on an empty library', function (): void {
        calmChannel();

        // "All caught up" is only true if there was anything to catch up on.
        Livewire::test('pages::feed')
            ->assertSee('No videos yet')
            ->assertDontSee('all caught up');
    });
});

describe('sampled channels in the feed', function (): void {
    it('leaves set-aside videos out of the feed', function (): void {
        $channel = calmChannel(['sample_limit' => 1]);
        $kept = Video::factory()->for($channel)->create(['title' => 'The Long One']);
        Video::factory()->for($channel)->setAside()->create(['title' => 'An Offcut']);

        Livewire::test('pages::feed')
            ->assertViewHas('videos', fn ($videos): bool => $videos->pluck('id')->all() === [$kept->id]);
    });

    it('says what the rule did rather than quietly dropping things', function (): void {
        $channel = calmChannel(['sample_limit' => 3]);
        Video::factory()->for($channel)->create();
        Video::factory()->for($channel)->setAside()->count(11)->create();

        Livewire::test('pages::feed')
            ->assertSee('Sampling set aside')
            ->assertSee('11')
            ->assertSee(CALM_CHANNEL_TITLE);
    });

    it('stays quiet when no channel is sampled', function (): void {
        Video::factory()->for(calmChannel())->count(2)->create();

        Livewire::test('pages::feed')->assertDontSee('Sampling set aside');
    });

    it('stays quiet when a sampled channel has had nothing set aside', function (): void {
        $channel = calmChannel(['sample_limit' => 10]);
        Video::factory()->for($channel)->count(2)->create();

        Livewire::test('pages::feed')->assertDontSee('Sampling set aside');
    });
});

describe('refreshing a feed nobody has looked at', function (): void {
    it('asks the page to refresh itself when the library has gone stale', function (): void {
        calmChannel(['last_refreshed_at' => now()->subHours(9)]);

        // wire:init, so the grid is on screen before any of it starts.
        Livewire::test('pages::feed')->assertSee('wire:init="autoRefresh"', escape: false);
    });

    it('does not when it was refreshed recently', function (): void {
        calmChannel(['last_refreshed_at' => now()->subMinutes(20)]);

        Livewire::test('pages::feed')->assertDontSee('wire:init="autoRefresh"', escape: false);
    });

    it('treats a channel that has never been refreshed as stale', function (): void {
        Channel::factory()->neverRefreshed()->create();

        Livewire::test('pages::feed')->assertSee('wire:init="autoRefresh"', escape: false);
    });

    it('stays quiet when following nothing', function (): void {
        Livewire::test('pages::feed')->assertDontSee('wire:init="autoRefresh"', escape: false);
    });

    it('can be turned off', function (): void {
        config()->set('calm-tube.refresh.auto_after_hours', 0);
        calmChannel(['last_refreshed_at' => now()->subDays(5)]);

        Livewire::test('pages::feed')->assertDontSee('wire:init="autoRefresh"', escape: false);
    });

    it('refreshes every enabled channel when it runs', function (): void {
        Bus::fake([RefreshChannel::class]);
        calmChannel(['last_refreshed_at' => now()->subHours(9)]);
        Channel::factory()->create(['last_refreshed_at' => now()->subHours(9)]);
        Channel::factory()->disabled()->create(['last_refreshed_at' => now()->subHours(9)]);

        Livewire::test('pages::feed')->call('autoRefresh');

        Bus::assertDispatchedSyncTimes(RefreshChannel::class, 2);
    });

    it('does nothing at all when the feed is fresh', function (): void {
        Bus::fake([RefreshChannel::class]);
        calmChannel(['last_refreshed_at' => now()->subMinutes(5)]);

        Livewire::test('pages::feed')->call('autoRefresh');

        Bus::assertNotDispatchedSync(RefreshChannel::class);
    });
});

describe('videos that arrive while you are reading', function (): void {
    it('announces them rather than sliding them into the grid', function (): void {
        $channel = calmChannel(['last_refreshed_at' => now()->subHours(9)]);
        $alreadyHere = Video::factory()->for($channel)->create();

        $component = Livewire::test('pages::feed');

        // Stand in for what a refresh would have created.
        $this->travelTo(now()->addMinute());
        $arrived = Video::factory()->for($channel)->create();

        $component->set('heldBackSince', now()->subSeconds(30)->toDateTimeString())
            ->set('heldBackCount', 1)
            ->assertViewHas('videos', fn ($videos): bool => $videos->pluck('id')->all() === [$alreadyHere->id])
            ->assertSee('arrived while you were away')
            ->assertSee('Show them');

        expect($arrived->exists)->toBeTrue();
    });

    it('shows them the moment you ask', function (): void {
        $channel = calmChannel();
        $alreadyHere = Video::factory()->for($channel)->create();
        $this->travelTo(now()->addMinute());
        $arrived = Video::factory()->for($channel)->create();

        Livewire::test('pages::feed')
            ->set('heldBackSince', now()->subSeconds(30)->toDateTimeString())
            ->set('heldBackCount', 1)
            ->call('showNew')
            ->assertViewHas('videos', fn ($videos): bool => $videos->contains($arrived))
            ->assertSet('heldBackSince', null);

        expect($alreadyHere->exists)->toBeTrue();
    });

    it('holds nothing back when you pressed refresh yourself', function (): void {
        Bus::fake([RefreshChannel::class]);
        calmChannel();

        // You asked, so you want to see the result, not a button about it.
        Livewire::test('pages::feed')
            ->set('heldBackSince', now()->toDateTimeString())
            ->call('refreshAll')
            ->assertSet('heldBackSince', null)
            ->assertSet('heldBackCount', 0);
    });

    it('says nothing when a refresh found nothing', function (): void {
        Storage::fake('local');
        fakeFeed('feed-empty.xml');
        calmChannel(['last_refreshed_at' => now()->subHours(9)]);

        Livewire::test('pages::feed')
            ->call('autoRefresh')
            ->assertSet('heldBackCount', 0)
            ->assertDontSee('arrived while you were away');
    });
});
