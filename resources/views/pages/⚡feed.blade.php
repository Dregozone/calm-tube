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

    /**
     * Opened without filters in the URL, the feed picks up where you left off.
     * Coming back from a video is the case that matters: watch something with
     * the unwatched filter on and you should land back on the unwatched list,
     * without the video you just finished.
     */
    public function mount(): void
    {
        if (! request()->has('filter')) {
            $this->filter = (string) session('calm-tube.feed.filter', 'all');
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

    public function refreshAll(): void
    {
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
        <flux:callout variant="secondary" class="mt-4">{{ $status }}</flux:callout>
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
        </div>
    @endunless

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
    @elseif ($videos->isEmpty() && $filter === 'unwatched')
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
