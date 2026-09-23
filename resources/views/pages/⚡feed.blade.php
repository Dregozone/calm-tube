<?php

use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use App\Support\RefreshResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
    public function feedChanged(): void
    {
        // Re-renders so a hidden video leaves the grid.
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

    public function refreshAll(): void
    {
        $this->undoable = [];
        $channels = Channel::query()->enabled()->get();
        $new = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            $result = RefreshChannel::dispatchSync($channel);

            if (! $result instanceof RefreshResult) {
                continue;
            }

            $result->isFailed() ? $failed++ : $new += $result->newVideos;
        }

        $this->status = sprintf(
            '%s.%s',
            $new === 0
                ? __('No new videos')
                : $new.' '.__('new').' '.($new === 1 ? __('video') : __('videos')),
            $failed === 0
                ? ''
                : ' '.$failed.' '.($failed === 1 ? __('channel') : __('channels')).' '.__('failed to refresh.')
        );

        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'videos' => $this->videos(),
            'channels' => Channel::query()->enabled()->orderBy('title')->get(),
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
            'lastRefreshedAt' => Channel::query()->max('last_refreshed_at'),
            'missingApiKey' => config('calm-tube.api_key') === null,
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
            ->orderByDesc('published_at')
            ->paginate((int) config('calm-tube.feed.per_page'));
    }
}; ?>

<section class="mx-auto w-full max-w-7xl px-4 py-8">
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

            <flux:button
                wire:click="markPageWatched"
                wire:confirm="{{ __('Mark every video on this page as watched?') }}"
                size="sm"
                variant="subtle"
                icon="check"
                class="sm:ms-auto"
            >
                {{ __('Mark page watched') }}
            </flux:button>
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
        <div class="mt-6 grid grid-cols-1 gap-x-5 gap-y-8 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($videos as $video)
                <livewire:video-card :video="$video" :wire:key="'card-'.$video->id" />
            @endforeach
        </div>

        <div class="mt-10 flex flex-col items-center gap-3">
            <flux:text size="sm">
                {{ __('Showing') }} {{ $videos->firstItem() }}–{{ $videos->lastItem() }}
                {{ __('of') }} {{ $videos->total() }}
            </flux:text>

            <div class="flex items-center gap-2">
                <flux:button
                    wire:click="previousPage"
                    :disabled="$videos->onFirstPage()"
                    variant="subtle"
                    size="sm"
                    icon="arrow-left"
                >
                    {{ __('Previous') }}
                </flux:button>

                <flux:button
                    wire:click="nextPage"
                    :disabled="! $videos->hasMorePages()"
                    variant="subtle"
                    size="sm"
                    icon:trailing="arrow-right"
                >
                    {{ __('Next') }}
                </flux:button>
            </div>
        </div>
    @endif
</section>
