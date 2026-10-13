<?php

use App\Livewire\ReadingListPage;
use App\Models\ReadingListItem;
use App\Services\ReadingList\ReadingListSync;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;

new #[Title('Videos')] class extends ReadingListPage
{
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $sync = app(ReadingListSync::class);

        return [
            'items' => $this->items(),
            'configured' => $sync->isConfigured(),
            'lastRefreshedAt' => $sync->lastRefreshedAt(),
            'stale' => $sync->isConfigured() && $sync->isStale(),
        ];
    }

    /**
     * In the reading list's own order, which is its priority. A video that
     * turned out to be a Short stays off the page, as it does everywhere.
     *
     * @return Collection<int, ReadingListItem>
     */
    private function items(): Collection
    {
        return ReadingListItem::query()
            ->pending()
            ->with('video.channel')
            ->orderBy('position')
            ->get()
            ->reject(fn (ReadingListItem $item): bool => $item->video?->is_short === true)
            ->values();
    }
}; ?>

<section class="w-full" @if ($stale) wire:init="autoRefresh" @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Videos') }}</flux:heading>

            <flux:text class="mt-1">
                {{ __('The YouTube videos on your reading list. Finish one to take it off.') }}
            </flux:text>
        </div>

        @if ($configured)
            <div class="flex flex-wrap items-center gap-3">
                @if ($lastRefreshedAt)
                    <flux:text size="sm" wire:loading.remove wire:target="refresh, autoRefresh">
                        {{ __('Last updated') }} {{ $lastRefreshedAt->diffForHumans() }}
                    </flux:text>
                @endif

                <flux:text size="sm" wire:loading wire:target="refresh, autoRefresh">
                    {{ __('Checking your reading list') }}…
                </flux:text>

                <flux:button
                    wire:click="refresh"
                    icon="arrow-path"
                    variant="subtle"
                    wire:loading.attr="disabled"
                    class="data-loading:opacity-50"
                >
                    {{ __('Refresh') }}
                </flux:button>
            </div>
        @endif
    </div>

    @if ($status)
        <flux:callout variant="secondary" class="mt-4">{{ $status }}</flux:callout>
    @endif

    @if ($error)
        <flux:callout variant="warning" class="mt-4">{{ $error }}</flux:callout>
    @endif

    @unless ($configured)
        <flux:callout variant="warning" class="mt-4">
            {{ __('Personal Assistant is not set up. Add READING_LIST_URL, READING_LIST_IDENTIFIER and READING_LIST_TOKEN to your .env.') }}
        </flux:callout>
    @endunless

    @if ($items->isEmpty())
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('No videos on your reading list.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('YouTube links added to the reading list in Personal Assistant show up here.') }}
            </flux:text>
        </div>
    @else
        <x-video-grid class="mt-5">
            @foreach ($items as $item)
                <article class="flex flex-col" wire:key="reading-{{ $item->id }}" data-reading-list-item="{{ $item->reading_list_id }}">
                    @if ($item->video)
                        <a href="{{ route('videos.watch', [$item->video, 'from' => 'videos']) }}" wire:navigate class="block">
                            <div class="relative overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                                <img
                                    src="{{ route('thumbnails.show', $item->video) }}"
                                    alt=""
                                    loading="lazy"
                                    class="aspect-video w-full object-cover"
                                />

                                @if ($item->video->watching_time_for_humans)
                                    <span class="absolute bottom-1.5 right-1.5 rounded bg-black/80 px-1.5 py-0.5 text-xs font-medium text-white tabular-nums">
                                        {{ $item->video->watching_time_for_humans }}
                                    </span>
                                @endif

                                @if ($item->video->percent_watched !== null && ! $item->video->isWatched())
                                    <div class="absolute inset-x-0 bottom-0 h-1 bg-black/40">
                                        <div class="h-full bg-red-500" style="width: {{ $item->video->percent_watched }}%"></div>
                                    </div>
                                @endif
                            </div>
                        </a>
                    @else
                        {{-- Not archived yet, which takes an API key: until it is,
                             YouTube is the only place it plays. --}}
                        <a
                            href="{{ $item->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex aspect-video w-full flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-zinc-300 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                        >
                            {{ __('Not archived yet') }}
                            <span class="inline-flex items-center gap-1">
                                {{ __('Open on YouTube') }}
                                <flux:icon.arrow-top-right-on-square variant="micro" />
                            </span>
                        </a>
                    @endif

                    <div class="mt-2 flex flex-1 flex-col">
                        @if ($item->video)
                            <a href="{{ route('videos.watch', [$item->video, 'from' => 'videos']) }}" wire:navigate>
                                <flux:heading class="line-clamp-2 leading-snug">{{ $item->title }}</flux:heading>
                            </a>
                        @else
                            <flux:heading class="line-clamp-2 leading-snug">{{ $item->title }}</flux:heading>
                        @endif

                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            @if ($item->category)
                                <flux:badge size="sm">{{ $item->category }}</flux:badge>
                            @endif

                            <flux:text size="sm" class="truncate">
                                @if ($item->video)
                                    {{ $item->video->channel->display_name }}
                                @endif

                                @if ($item->percent_complete > 0)
                                    @if ($item->video) · @endif
                                    <span class="tabular-nums">{{ $item->percent_complete }}%</span> {{ __('done') }}
                                @endif
                            </flux:text>
                        </div>
                    </div>
                </article>
            @endforeach
        </x-video-grid>
    @endif
</section>
