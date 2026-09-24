<?php

use App\Enums\RefreshTrigger;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use App\Support\RefreshResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Feed')] class extends Component
{
    use WithPagination;

    /** all | unwatched */
    #[Url]
    public string $filter = 'all';

    /** A YouTube channel id, or empty for every channel. */
    #[Url]
    public string $channel = '';

    public ?string $status = null;

    /** Video ids the last bulk action touched, so it can be undone. */
    public array $undoable = [];

    /**
     * Set when a refresh you did not ask for found something. Videos created
     * after this moment wait behind a button rather than appearing under your
     * cursor and shifting everything down a row.
     */
    public ?string $heldBackSince = null;

    public int $heldBackCount = 0;

    /**
     * Opened without filters in the URL, the feed picks up where you left off.
     * Coming back from a video is the case that matters: watch something with
     * the unwatched filter on and you should land back on the unwatched list,
     * without the video you just finished.
     */
    public function mount(): void
    {
        if (! request()->has('filter')) {
            $this->filter = (string) session(
                'calm-tube.feed.filter',
                (string) config('calm-tube.feed.default_filter'),
            );
        }

        if (! request()->has('channel')) {
            $this->channel = (string) session('calm-tube.feed.channel', '');
        }

        $this->rememberFilters();
    }

    public function updated(): void
    {
        $this->resetPage();
        $this->rememberFilters();
    }

    private function rememberFilters(): void
    {
        session()->put('calm-tube.feed.filter', $this->filter);
        session()->put('calm-tube.feed.channel', $this->channel);
    }

    #[On('feed-changed')]
    #[On('channel-saved')]
    public function feedChanged(): void
    {
        // Re-renders so a hidden video leaves the grid, and a channel edited
        // from a card shows its new name, speed and limit.
    }

    /**
     * Clears the page in front of you rather than the whole backlog.
     *
     * A page is a decision you can see the whole of, which the 771 videos
     * behind it is not. Undo is offered because the only thing worse than a
     * chore is a chore you cannot take back.
     */
    public function markPageWatched(): void
    {
        $ids = $this->videos()->getCollection()
            ->filter(fn (Video $video): bool => ! $video->isWatched())
            ->pluck('id')
            ->all();

        $this->markWatched($ids);

        $this->status = $ids === []
            ? __('Everything on this page was already watched.')
            : count($ids).' '.(count($ids) === 1 ? __('video') : __('videos')).' '.__('marked as watched.');
    }

    /**
     * @param  list<int>  $ids
     */
    private function markWatched(array $ids): void
    {
        if ($ids === []) {
            $this->undoable = [];

            return;
        }

        Video::query()->whereKey($ids)->update([
            'watched_at' => now(),
            'resume_seconds' => null,
        ]);

        $this->undoable = $ids;
        $this->resetPage();
    }

    public function undoBulk(): void
    {
        if ($this->undoable === []) {
            return;
        }

        Video::query()->whereKey($this->undoable)->update(['watched_at' => null]);

        $this->status = count($this->undoable).' '.
            (count($this->undoable) === 1 ? __('video') : __('videos')).' '.__('put back.');
        $this->undoable = [];
        $this->resetPage();
    }

    /**
     * Refreshes a feed nobody has looked at for a while, once the page has
     * already rendered.
     *
     * This is why the app needs no scheduler and no queue worker: opening it
     * is the trigger. Run through wire:init so the grid is on screen and
     * usable before any of it starts.
     */
    public function autoRefresh(): void
    {
        $channels = Channel::query()->enabled()->get();

        if (! $this->isStale($channels)) {
            return;
        }

        $since = now();
        $new = 0;

        foreach ($channels as $channel) {
            $result = RefreshChannel::dispatchSync($channel, RefreshTrigger::Stale);

            if ($result instanceof RefreshResult && ! $result->isFailed()) {
                // Only what you would actually see: a haul of Shorts should
                // not announce itself as videos waiting for you.
                $new += $result->reachedFeed;
            }
        }

        if ($new === 0) {
            return;
        }

        // Announced, not inserted. Arriving somewhere new is always your
        // click, and a grid that reshuffles while you are reading it is the
        // feed behaviour this app exists to avoid.
        $this->heldBackSince = $since->toDateTimeString();
        $this->heldBackCount = $new;
    }

    public function showNew(): void
    {
        $this->heldBackSince = null;
        $this->heldBackCount = 0;
        $this->resetPage();
    }

    /**
     * Nothing has been refreshed for longer than the configured window.
     *
     * A channel that has never been refreshed counts as stale, which is what
     * a freshly added one wants.
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function isStale(Collection $channels): bool
    {
        $hours = (int) config('calm-tube.refresh.auto_after_hours');

        if ($hours <= 0 || $channels->isEmpty()) {
            return false;
        }

        $last = $channels->max('last_refreshed_at');

        return $last === null || Date::parse($last)->lt(now()->subHours($hours));
    }

    public function refreshAll(): void
    {
        $this->undoable = [];
        $this->showNew();

        $results = Channel::query()->enabled()->get()
            ->map(fn (Channel $channel) => RefreshChannel::dispatchSync($channel))
            ->filter(fn ($result): bool => $result instanceof RefreshResult);

        $this->status = RefreshResult::summarise($results);

        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $channels = Channel::query()->enabled()->orderBy('title')->get();

        return [
            'videos' => $this->videos(),
            'channels' => $channels,
            'followsNothing' => Channel::query()->count() === 0,
            // "All caught up" is only true if there was anything to catch up
            // on. An empty library needs a refresh, not congratulations.
            'hasVideos' => Video::query()->inFeed()->exists(),
            'sampledChannels' => Channel::query()
                ->enabled()
                ->whereNotNull('sample_limit')
                ->withCount(['videos as set_aside_count' => fn ($query) => $query->setAside()])
                ->orderBy('title')
                ->get(),
            'lastRefreshedAt' => $channels->max('last_refreshed_at'),
            'missingApiKey' => config('calm-tube.api_key') === null,
            'stale' => $this->heldBackSince === null && $this->isStale($channels),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Video>
     */
    private function videos(): LengthAwarePaginator
    {
        return Video::query()
            ->inFeed()
            ->with('channel')
            ->when($this->filter === 'unwatched', fn ($query) => $query->unwatched())
            ->when($this->channel !== '', fn ($query) => $query->whereRelation(
                'channel', 'youtube_channel_id', $this->channel
            ))
            ->when($this->heldBackSince, fn ($query) => $query->where('created_at', '<=', $this->heldBackSince))
            ->orderByDesc('published_at')
            ->paginate((int) config('calm-tube.feed.per_page'));
    }
}; ?>

<section class="w-full" @if ($stale) wire:init="autoRefresh" @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Feed') }}</flux:heading>

        <div class="flex flex-wrap items-center gap-3">
            @if ($lastRefreshedAt)
                <flux:text size="sm" wire:loading.remove wire:target="refreshAll">
                    {{ __('Last refreshed') }} {{ \Illuminate\Support\Carbon::parse($lastRefreshedAt)->diffForHumans() }}
                </flux:text>
            @endif

            <flux:text size="sm" wire:loading wire:target="refreshAll">
                {{ __('Refreshing') }}…
            </flux:text>

            <flux:text size="sm" wire:loading wire:target="autoRefresh">
                {{ __('Checking for new videos') }}…
            </flux:text>

            @unless ($followsNothing)
                <flux:button
                    wire:click="refreshAll"
                    icon="arrow-path"
                    variant="subtle"
                    wire:loading.attr="disabled"
                    class="data-loading:opacity-50"
                >
                    {{ __('Refresh all') }}
                </flux:button>
            @endunless
        </div>
    </div>

    @if ($status)
        <flux:callout variant="secondary" class="mt-4">
            <div class="flex flex-wrap items-center gap-3">
                <span>{{ $status }}</span>

                @if ($undoable !== [])
                    <flux:button wire:click="undoBulk" size="xs" variant="subtle">
                        {{ __('Undo') }}
                    </flux:button>
                @endif
            </div>
        </flux:callout>
    @endif

    @if ($heldBackCount > 0)
        <flux:callout variant="secondary" class="mt-4">
            <div class="flex flex-wrap items-center gap-3">
                <span>
                    {{ $heldBackCount }}
                    {{ $heldBackCount === 1 ? __('new video') : __('new videos') }}
                    {{ __('arrived while you were away.') }}
                </span>

                <flux:button wire:click="showNew" size="xs" variant="primary">
                    {{ __('Show them') }}
                </flux:button>
            </div>
        </flux:callout>
    @endif

    @if ($missingApiKey)
        <flux:callout variant="warning" class="mt-4">
            {{ __('No YouTube API key set, so durations are unavailable. Add YOUTUBE_API_KEY to your .env.') }}
        </flux:callout>
    @endif

    @unless ($followsNothing)
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
                <flux:radio value="all">{{ __('All') }}</flux:radio>
                <flux:radio value="unwatched">{{ __('Unwatched') }}</flux:radio>
            </flux:radio.group>

            <flux:select wire:model.live="channel" size="sm" class="max-w-64" data-calm-focus>
                <flux:select.option value="">{{ __('All channels') }}</flux:select.option>

                @foreach ($channels as $option)
                    <flux:select.option value="{{ $option->youtube_channel_id }}">
                        {{ $option->display_name }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- <flux:button
                wire:click="markPageWatched"
                wire:confirm="{{ __('Mark every video on this page as watched?') }}"
                size="sm"
                variant="subtle"
                icon="check"
                class="sm:ms-auto"
            >
                {{ __('Mark page watched') }}
            </flux:button> --}}
        </div>
    @endunless

    @if ($sampledChannels->isNotEmpty() && $sampledChannels->sum('set_aside_count') > 0)
        <flux:text size="sm" class="mt-4 block">
            {{ __('Sampling set aside') }}
            {{ $sampledChannels->sum('set_aside_count') }}
            {{ __('videos from') }}
            {{ $sampledChannels->pluck('display_name')->join(', ', ' and ') }}.
            <a href="{{ route('channels.index') }}" wire:navigate class="underline">
                {{ __('They are on their channel pages.') }}
            </a>
        </flux:text>
    @endif

    @if ($followsNothing)
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('Nothing here yet.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Follow a channel to start building your feed.') }}
            </flux:text>

            <flux:button :href="route('channels.index')" wire:navigate variant="primary" class="mt-6">
                {{ __('Add a channel') }}
            </flux:button>
        </div>
    @elseif ($videos->isEmpty() && $filter === 'unwatched' && $hasVideos)
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __("You're all caught up.") }}</flux:heading>

            <flux:button wire:click="$set('filter', 'all')" variant="subtle" class="mt-6">
                {{ __('Show everything') }}
            </flux:button>
        </div>
    @elseif ($videos->isEmpty())
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('No videos yet.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Refresh to fetch the latest uploads from the channels you follow.') }}
            </flux:text>
        </div>
    @else
        <x-video-grid class="mt-5">
            @foreach ($videos as $video)
                <livewire:video-card :video="$video" :wire:key="'card-'.$video->id" />
            @endforeach
        </x-video-grid>

        <x-pager :paginator="$videos" class="mt-8" />
    @endif

    <livewire:channel-editor />
</section>
