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

    it('uses the configured page size', function (): void {
        config()->set('calm-tube.feed.per_page', 5);
        Video::factory()->for(calmChannel())->count(8)->create();

        Livewire::test('pages::feed')->assertViewHas('videos', fn ($videos): bool => $videos->count() === 5);
    });
});

describe('filters', function (): void {
    it('shows only unwatched videos when asked', function (): void {
        $channel = calmChannel();
        $unwatched = Video::factory()->for($channel)->create(['title' => 'Not Seen Yet']);
        $watched = Video::factory()->for($channel)->watched()->create(['title' => 'Already Seen']);

        Livewire::test('pages::feed')
            ->set('filter', 'unwatched')
            ->assertSee($unwatched->title)
            ->assertDontSee($watched->title);
    });

    it('shows only the selected channel', function (): void {
        $wanted = Video::factory()->for(calmChannel())->create(['title' => 'From The Channel I Picked']);
        $other = Video::factory()->for(Channel::factory())->create(['title' => 'From Another Channel']);

        Livewire::test('pages::feed')
            ->set('channel', $wanted->channel->youtube_channel_id)
            ->assertSee($wanted->title)
            ->assertDontSee($other->title);
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
