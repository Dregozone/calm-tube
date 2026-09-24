<?php

use App\Enums\RefreshTrigger;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Models\Video;
use App\Support\RefreshResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public Channel $channel;

    /** all | unwatched | set-aside */
    #[Url]
    public string $filter = 'all';

    public ?string $status = null;

    /** Video ids the last bulk action touched, so it can be undone. */
    public array $undoable = [];

    public function updated(): void
    {
        $this->resetPage();
    }

    /**
     * The tab says which channel you are looking at, which a static title
     * could not.
     */
    public function render(): View
    {
        return $this->view()->title($this->channel->display_name);
    }

    /**
     * Refreshing here works on a disabled channel too. Disabling stops the
     * scheduled sweep touching it; asking for it directly is still an answer.
     */
    public function refreshChannel(): void
    {
        $result = RefreshChannel::dispatchSync($this->channel, RefreshTrigger::Manual);

        if (! $result instanceof RefreshResult) {
            return;
        }

        $this->channel->refresh();

        $this->undoable = [];

        $this->status = $result->isFailed()
            ? $result->errorMessage
            : $result->summary();

        $this->resetPage();
    }

    public function snooze(): void
    {
        $this->channel->snooze();
        $this->status = __('Snoozed until :date. What is already here stays; nothing published until then will reach your feed.', [
            'date' => $this->channel->snoozed_until?->format('D j M'),
        ]);
        $this->undoable = [];
    }

    public function wake(): void
    {
        $this->channel->wake();
        $this->status = __('Awake. New uploads reach your feed again; anything published while it slept stays out.');
        $this->undoable = [];
    }

    /**
     * Declares a channel finished with, in one action rather than fifty.
     *
     * Nothing is deleted and nothing is hidden: these are still your archive,
     * still searchable through the channel page, just no longer waiting.
     */
    public function markAllWatched(): void
    {
        $ids = $this->archive()->unwatched()->pluck('id')->all();

        if ($ids === []) {
            $this->status = __('Nothing here was unwatched.');
            $this->undoable = [];

            return;
        }

        Video::query()->whereKey($ids)->update([
            'watched_at' => now(),
            'resume_seconds' => null,
        ]);

        $this->undoable = $ids;
        $this->status = count($ids).' '.
            (count($ids) === 1 ? __('video') : __('videos')).' '.__('marked as watched.');

        unset($this->unwatchedCount);
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

        unset($this->unwatchedCount);
        $this->resetPage();
    }

    #[Computed]
    public function total(): int
    {
        return $this->archive()->count();
    }

    #[Computed]
    public function unwatchedCount(): int
    {
        return $this->archive()->unwatched()->count();
    }

    #[Computed]
    public function setAsideCount(): int
    {
        return $this->archive()->setAside()->count();
    }

    public function youtubeUrl(): string
    {
        return 'https://www.youtube.com/channel/'.$this->channel->youtube_channel_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['videos' => $this->videos()];
    }

    /**
     * Everything this channel archived, whether or not you still follow it.
     *
     * @return Builder<Video>
     */
    private function archive(): Builder
    {
        return $this->channel->videos()->getQuery()->viewable();
    }

    /**
     * @return LengthAwarePaginator<int, Video>
     */
    private function videos(): LengthAwarePaginator
    {
        return $this->archive()
            ->with('channel')
            ->when($this->filter === 'unwatched', fn (Builder $query) => $query->unwatched())
            ->when($this->filter === 'set-aside', fn (Builder $query) => $query->setAside())
            ->orderByDesc('published_at')
            ->paginate((int) config('calm-tube.feed.per_page'));
    }
}; ?>

<section class="w-full">
    <flux:button :href="route('channels.index')" wire:navigate variant="subtle" size="sm" icon="arrow-left">
        {{ __('All channels') }}
    </flux:button>

    <div class="mt-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-center gap-4">
            <img
                src="{{ route('avatars.show', $channel) }}"
                alt=""
                class="size-14 shrink-0 rounded-full bg-zinc-200 object-cover dark:bg-zinc-700"
            />

            <div class="min-w-0">
                <flux:heading size="xl" level="1" class="truncate">{{ $channel->display_name }}</flux:heading>

                <flux:text size="sm" class="mt-1 block">
                    {{ $this->total }} {{ Str::plural('video', $this->total) }}
                    · {{ $this->unwatchedCount }} {{ __('unwatched') }}

                    @if ($channel->handle)
                        · {{ $channel->handle }}
                    @endif

                    @if ($channel->isSnoozed())
                        · {{ __('snoozed until :date', ['date' => $channel->snoozed_until->format('D j M')]) }}
                    @endif

                    @unless ($channel->is_enabled)
                        · {{ __('disabled, hidden from your feed') }}
                    @endunless
                </flux:text>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if ($channel->isSnoozed())
                <flux:button wire:click="wake" icon="sun" variant="subtle" size="sm">
                    <span class="hidden sm:inline">{{ __('Wake up') }}</span>
                </flux:button>
            @else
                <flux:button wire:click="snooze" icon="moon" variant="subtle" size="sm">
                    <span class="hidden sm:inline">{{ __('Snooze :days days', ['days' => config('calm-tube.feed.snooze_days')]) }}</span>
                </flux:button>
            @endif

            <flux:button
                wire:click="refreshChannel"
                icon="arrow-path"
                variant="subtle"
                size="sm"
                wire:loading.attr="disabled"
                class="data-loading:opacity-50"
            >
                <span class="hidden sm:inline">{{ __('Refresh') }}</span>
            </flux:button>

            <a
                href="{{ $this->youtubeUrl() }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white"
            >
                {{ __('Open on YouTube') }}
                <flux:icon.arrow-top-right-on-square variant="micro" />
            </a>
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

    @if ($channel->isSampled())
        <flux:callout variant="secondary" class="mt-4">
            @if ($channel->isPickedWeekly())
                {{ __('Picking up to :n a week from this channel, once each week is over.', ['n' => $channel->sample_limit]) }}
            @else
                {{ __('Keeping the :n longest uploads a day from this channel.', ['n' => $channel->sample_limit]) }}
            @endif
            {{ __(':aside of :total set aside — they are all still here, just not in your feed.', [
                'aside' => $this->setAsideCount,
                'total' => $this->total,
            ]) }}
        </flux:callout>
    @endif

    @if ($channel->hasRefreshError())
        <flux:callout variant="danger" class="mt-4">
            {{ __('The last refresh failed') }} — {{ $channel->last_refresh_error }}
        </flux:callout>
    @elseif ($channel->last_refreshed_at)
        <flux:text size="sm" class="mt-4 block">
            {{ __('Refreshed') }} {{ $channel->last_refreshed_at->diffForHumans() }}
        </flux:text>
    @endif

    @if ($this->total > 0)
        <div class="mt-6">
            <div class="flex flex-wrap items-center gap-3">
                <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
                    <flux:radio value="all">{{ __('All') }}</flux:radio>
                    <flux:radio value="unwatched">{{ __('Unwatched') }}</flux:radio>

                    @if ($this->setAsideCount > 0)
                        <flux:radio value="set-aside">{{ __('Set aside') }}</flux:radio>
                    @endif
                </flux:radio.group>

                @if ($this->unwatchedCount > 0)
                    <flux:button
                        wire:click="markAllWatched"
                        wire:confirm="{{ __('Mark all :count videos from this channel as watched?', ['count' => $this->unwatchedCount]) }}"
                        size="sm"
                        variant="subtle"
                        icon="check"
                    >
                        {{ __('Mark all as watched') }}
                    </flux:button>
                @endif
            </div>
        </div>
    @endif

    @if ($this->total === 0)
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('No videos yet.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Refresh to fetch this channel’s latest uploads.') }}
            </flux:text>

            <flux:button wire:click="refreshChannel" variant="primary" icon="arrow-path" class="mt-6">
                {{ __('Refresh') }}
            </flux:button>
        </div>
    @elseif ($videos->isEmpty())
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __("You're all caught up.") }}</flux:heading>

            <flux:button wire:click="$set('filter', 'all')" variant="subtle" class="mt-6">
                {{ __('Show everything') }}
            </flux:button>
        </div>
    @else
        <x-video-grid class="mt-5">
            @foreach ($videos as $video)
                <livewire:video-card :video="$video" :wire:key="'card-'.$video->id" />
            @endforeach
        </x-video-grid>

        <x-pager :paginator="$videos" class="mt-8" />
    @endif
</section>
