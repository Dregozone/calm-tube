<?php

use App\Enums\RefreshTrigger;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use App\Support\RefreshResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as PageOfVideos;
use Illuminate\Support\Carbon;
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

    /** all | unwatched | saved */
    #[Url]
    public string $filter = 'all';

    /** newest | oldest | longest | shortest */
    #[Url]
    public string $sort = 'newest';

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
     * Channels a refresh you did not ask for has still to reach, and when it
     * began, while it works through them a few requests at a time.
     *
     * @var list<int>
     */
    public array $refreshQueue = [];

    public ?string $refreshStartedAt = null;

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

        if (! request()->has('sort')) {
            $this->sort = (string) session('calm-tube.feed.sort', 'newest');
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
        session()->put('calm-tube.feed.sort', $this->sort);
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
     *
     * Every channel at once is a minute and a half of HTTP, longer than the
     * proxy in front of the site will wait, so each request takes the
     * stalest few that fit the batch budget and asks the page for the next.
     */
    public function autoRefresh(): void
    {
        if ($this->refreshQueue === []) {
            $channels = Channel::query()->enabled()->get();

            if (! $this->isStale($channels)) {
                return;
            }

            $this->refreshQueue = $channels
                ->sortBy(fn (Channel $channel): int => $channel->last_refreshed_at?->getTimestamp() ?? 0)
                ->modelKeys();
            $this->refreshStartedAt = now()->toDateTimeString();
        }

        $deadline = now()->addSeconds((int) config('calm-tube.refresh.auto_batch_seconds'));
        $new = 0;

        do {
            $channel = Channel::query()->enabled()->find(array_shift($this->refreshQueue));

            if ($channel === null) {
                continue;
            }

            $result = RefreshChannel::dispatchSync($channel, RefreshTrigger::Stale);

            if ($result instanceof RefreshResult && ! $result->isFailed()) {
                // Only what you would actually see: a haul of Shorts should
                // not announce itself as videos waiting for you.
                $new += $result->reachedFeed;
            }
        } while ($this->refreshQueue !== [] && now()->lt($deadline));

        if ($this->refreshQueue !== []) {
            $this->js('$wire.autoRefresh()');
        }

        if ($new === 0) {
            return;
        }

        // Announced, not inserted. Arriving somewhere new is always your
        // click, and a grid that reshuffles while you are reading it is the
        // feed behaviour this app exists to avoid. Held back from when the
        // refresh began, so an earlier batch's arrivals stay held back too.
        $this->heldBackSince ??= $this->refreshStartedAt;
        $this->heldBackCount += $new;
    }

    public function showNew(): void
    {
        $this->heldBackSince = null;
        $this->heldBackCount = 0;
        $this->resetPage();

        // A refresh still going holds back only what arrives from here on.
        if ($this->refreshQueue !== []) {
            $this->refreshStartedAt = now()->toDateTimeString();
        }
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
        $this->refreshQueue = [];
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
            'remaining' => $this->remainingForHumans(),
            'channels' => $channels,
            'followsNothing' => Channel::query()->followed()->doesntExist(),
            // "All caught up" is only true if there was anything to catch up
            // on. An empty library needs a refresh, not congratulations.
            'hasVideos' => Video::query()->inFeed()->exists(),
            // This week's selection rather than all time: it is the pool a
            // weekly channel is still to be picked from, and it shows how much
            // the limits are filtering now rather than ever.
            'setAsideThisWeek' => Video::query()
                ->setAside()
                ->where('published_at', '>=', now()->startOfWeek(Carbon::MONDAY))
                ->whereHas('channel', fn (Builder $channelQuery): Builder => $channelQuery
                    ->enabled()
                    ->whereNotNull('sample_limit'))
                ->count(),
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
        $perPage = (int) config('calm-tube.feed.per_page');

        if (in_array($this->sort, ['longest', 'shortest'], true)) {
            return $this->videosByTimeLeft($perPage);
        }

        return $this->feedQuery()
            ->with('channel')
            ->when(
                $this->sort === 'oldest',
                fn (Builder $query): Builder => $query->orderBy('published_at'),
                fn (Builder $query): Builder => $query->orderByDesc('published_at'),
            )
            ->paginate($perPage);
    }

    /**
     * Ordered by what each card says it will take: the channel's speed and
     * outro applied and only what is left of one part watched. That is worked
     * out in PHP by the model rather than repeated in SQL, so the whole
     * filtered set is ranked and only the page in front of you is loaded in
     * full, the same way the remaining time is added up.
     *
     * A video whose length is unknown goes last either way, and ties keep
     * the newest first.
     *
     * @return LengthAwarePaginator<int, Video>
     */
    private function videosByTimeLeft(int $perPage): LengthAwarePaginator
    {
        $longestFirst = $this->sort === 'longest';

        $ranked = $this->feedQuery()
            ->with('channel')
            ->orderByDesc('published_at')
            ->get(['id', 'channel_id', 'duration_seconds', 'resume_seconds', 'watched_at'])
            ->sortBy(fn (Video $video): array => [
                $video->watching_seconds === null ? 1 : 0,
                $longestFirst ? -($video->watching_seconds ?? 0) : ($video->watching_seconds ?? 0),
            ])
            ->pluck('id')
            ->values();

        $page = $this->getPage();
        $ids = $ranked->forPage($page, $perPage)->all();

        $videos = Video::query()
            ->with('channel')
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Video $video): int|false => array_search($video->id, $ids, true))
            ->values();

        return new PageOfVideos($videos, $ranked->count(), $perPage, $page);
    }

    /**
     * What it would take to get through everything the filters are showing,
     * every page of it rather than only the one in front of you, as H:MM.
     *
     * Each video counts what its card says it will take: the channel's speed
     * applied, the outro left off, and only what is left of one you are part
     * way through. A watched video has nothing remaining, so the All tab only
     * adds the saved videos to the Unwatched tab's answer. Null when there is
     * nothing to add up, rather than a heading claiming 0:00.
     */
    private function remainingForHumans(): ?string
    {
        $seconds = $this->feedQuery()
            ->unwatched()
            ->whereNotNull('duration_seconds')
            ->with('channel')
            ->get(['id', 'channel_id', 'duration_seconds', 'resume_seconds', 'watched_at'])
            ->sum(fn (Video $video): int => (int) $video->watching_seconds);

        $minutes = (int) round($seconds / 60);

        if ($minutes === 0) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * The feed as the filters have narrowed it, before ordering or paging.
     *
     * @return Builder<Video>
     */
    private function feedQuery(): Builder
    {
        return Video::query()
            ->inFeed()
            // All is everything in the feed, saved or not, watched or not.
            // Unwatched is what is still to do, less what you saved for
            // another time, and Saved is only that.
            ->when($this->filter === 'unwatched', fn ($query) => $query->unwatched()->notSavedForLater())
            ->when($this->filter === 'saved', fn ($query) => $query->savedForLater())
            ->when($this->channel !== '', fn ($query) => $query->whereRelation(
                'channel', 'youtube_channel_id', $this->channel
            ))
            ->when($this->heldBackSince, fn ($query) => $query->where('created_at', '<=', $this->heldBackSince));
    }
}; ?>

<section class="w-full" @if ($stale) wire:init="autoRefresh" @endif>
    <div class="flex items-center justify-between gap-3">
        <flux:heading size="xl" level="1">
            {{ __('Feed') }}

            @if ($remaining)
                <span class="font-normal text-zinc-500 dark:text-zinc-400" title="{{ __('Time left to watch everything shown, at each channel’s speed') }}">· {{ $remaining }} {{ __('left') }}</span>
            @endif
        </flux:heading>

        <div class="flex items-center gap-3">
            @if ($lastRefreshedAt)
                <flux:text size="sm" class="max-sm:hidden" wire:loading.remove wire:target="refreshAll,autoRefresh">
                    {{ __('Last refreshed') }} {{ \Illuminate\Support\Carbon::parse($lastRefreshedAt)->diffForHumans() }}
                </flux:text>
            @endif

            <flux:text size="sm" wire:loading wire:target="refreshAll">
                {{ __('Refreshing') }}…
            </flux:text>

            <flux:text size="sm" wire:loading wire:target="autoRefresh">
                <span class="sm:hidden">{{ __('Checking') }}…</span>
                <span class="max-sm:hidden">{{ __('Checking for new videos') }}…</span>
            </flux:text>

            @unless ($followsNothing)
                <flux:button
                    wire:click="refreshAll"
                    icon="arrow-path"
                    variant="ghost"
                    size="sm"
                    aria-label="{{ __('Refresh all') }}"
                    wire:loading.attr="disabled"
                    class="data-loading:opacity-50 max-sm:-me-2"
                >
                    <span class="max-sm:hidden">{{ __('Refresh all') }}</span>
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
        @php
            $sortOptions = [
                'newest' => __('Newest first'),
                'oldest' => __('Oldest first'),
                'longest' => __('Longest first'),
                'shortest' => __('Shortest first'),
            ];
            $selectedChannel = $channels->firstWhere('youtube_channel_id', $channel);
            $narrowedBy = ($sort !== 'newest' ? 1 : 0) + ($selectedChannel ? 1 : 0);
        @endphp

        <div class="mt-5 flex items-center gap-2 sm:gap-3">
            <flux:radio.group wire:model.live="filter" variant="segmented" size="sm" class="flex-1 sm:max-w-80">
                <flux:radio value="all">{{ __('All') }}</flux:radio>
                <flux:radio value="unwatched">{{ __('Unwatched') }}</flux:radio>
                <flux:radio value="saved">{{ __('Saved') }}</flux:radio>
            </flux:radio.group>

            {{-- A phone has room for one button beside the tabs: sort and
                 channel live behind it, in a sheet. --}}
            <flux:modal.trigger name="feed-filters" class="sm:hidden">
                <flux:button size="sm" icon="adjustments-horizontal" aria-label="{{ __('Sort and channel') }}">
                    @if ($narrowedBy > 0)
                        <flux:badge size="sm" color="blue" class="-me-1">{{ $narrowedBy }}</flux:badge>
                    @endif
                </flux:button>
            </flux:modal.trigger>

            <div class="ms-auto flex items-center gap-2 max-sm:hidden">
                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="user-circle" icon:trailing="chevron-down" data-calm-focus>
                        {{ $selectedChannel?->display_name ?? __('All channels') }}
                    </flux:button>

                    <flux:menu class="max-h-96 overflow-y-auto">
                        <flux:menu.radio.group wire:model.live="channel">
                            <flux:menu.radio value="">{{ __('All channels') }}</flux:menu.radio>

                            @foreach ($channels as $option)
                                <flux:menu.radio value="{{ $option->youtube_channel_id }}">{{ $option->display_name }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>

                <flux:dropdown align="end">
                    <flux:button size="sm" variant="ghost" icon="arrows-up-down" icon:trailing="chevron-down" aria-label="{{ __('Sort by') }}">
                        {{ $sortOptions[$sort] ?? $sortOptions['newest'] }}
                    </flux:button>

                    <flux:menu>
                        <flux:menu.radio.group wire:model.live="sort">
                            @foreach ($sortOptions as $value => $label)
                                <flux:menu.radio value="{{ $value }}">{{ $label }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>
            </div>

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

        {{-- What the hidden sheet is narrowing the feed by, each a tap to undo. --}}
        @if ($narrowedBy > 0)
            <div class="mt-3 flex flex-wrap gap-2 sm:hidden">
                @if ($selectedChannel)
                    <flux:badge size="sm">
                        {{ $selectedChannel->display_name }}
                        <flux:badge.close wire:click="$set('channel', '')" aria-label="{{ __('Show all channels') }}" />
                    </flux:badge>
                @endif

                @if ($sort !== 'newest')
                    <flux:badge size="sm">
                        {{ $sortOptions[$sort] ?? '' }}
                        <flux:badge.close wire:click="$set('sort', 'newest')" aria-label="{{ __('Sort newest first') }}" />
                    </flux:badge>
                @endif
            </div>
        @endif

        <flux:modal name="feed-filters" variant="flyout" position="bottom" class="rounded-t-2xl p-6!">
            <flux:heading size="lg">{{ __('Sort and channel') }}</flux:heading>

            <flux:subheading class="mt-6 mb-2 font-medium">{{ __('Sort') }}</flux:subheading>
            <flux:radio.group wire:model.live="sort" variant="segmented" size="sm">
                <flux:radio value="newest">{{ __('Newest') }}</flux:radio>
                <flux:radio value="oldest">{{ __('Oldest') }}</flux:radio>
                <flux:radio value="longest">{{ __('Longest') }}</flux:radio>
                <flux:radio value="shortest">{{ __('Shortest') }}</flux:radio>
            </flux:radio.group>

            <flux:subheading class="mt-6 mb-3 font-medium">{{ __('Channel') }}</flux:subheading>
            <flux:radio.group wire:model.live="channel" class="max-h-72 overflow-y-auto">
                <flux:radio value="" label="{{ __('All channels') }}" />

                @foreach ($channels as $option)
                    <flux:radio value="{{ $option->youtube_channel_id }}" label="{{ $option->display_name }}" />
                @endforeach
            </flux:radio.group>

            <flux:modal.close>
                <flux:button variant="primary" class="mt-6 w-full">{{ __('Show videos') }}</flux:button>
            </flux:modal.close>
        </flux:modal>
    @endunless

    @if ($setAsideThisWeek > 0)
        <flux:text size="sm" class="mt-4 block">
            {{ trans_choice('Sampling set aside :count video.|Sampling set aside :count videos.', $setAsideThisWeek) }}
            <a href="{{ route('channels.index') }}" wire:navigate class="underline">
                {{ __('See your channels.') }}
            </a>
            <span class="block">
                {{ __('Published this week (since Monday) and kept out of your feed by your channel limits.') }}
            </span>
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
    @elseif ($videos->isEmpty() && $filter === 'saved')
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('Nothing saved for later.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Save a video from its page to keep it here, out of your main feed.') }}
            </flux:text>
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
