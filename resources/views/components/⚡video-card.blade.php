<?php

use App\Models\Video;
use Flux\Flux;
use Livewire\Component;

new class extends Component
{
    public Video $video;

    public function toggleWatched(): void
    {
        $this->video->forceFill([
            'watched_at' => $this->video->isWatched() ? null : now(),
        ])->save();
    }

    public function hide(): void
    {
        $this->video->forceFill(['hidden_at' => now()])->save();

        $this->dispatch('feed-changed');
    }

    /**
     * Right where a noisy channel is noticed. What is already in the feed
     * stays; nothing it publishes for the next week will join it.
     */
    public function snoozeChannel(): void
    {
        $channel = $this->video->channel;
        $channel->snooze();

        Flux::toast(text: __(':channel snoozed until :date. What is already here stays.', [
            'channel' => $channel->display_name,
            'date' => $channel->snoozed_until?->format('D j M'),
        ]));

        $this->dispatch('feed-changed');
    }
}; ?>

<article class="group flex flex-col" wire:key="video-{{ $video->id }}">
    <div class="relative">
        <a href="{{ route('videos.watch', $video) }}" wire:navigate class="block">
            <div class="relative overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                {{-- Archived thumbnails keep whatever aspect YouTube served, so the
                     container crops rather than the source being trusted. --}}
                <img
                    src="{{ route('thumbnails.show', $video) }}"
                    alt=""
                    loading="lazy"
                    class="aspect-video w-full object-cover {{ $video->isWatched() ? 'opacity-50' : '' }}"
                />

                {{-- How long it will take you, not how long it is: the channel's
                     speed and outro skip are applied. The watch page has the
                     real duration. --}}
                @if ($video->watching_time_for_humans)
                    <span
                        class="absolute bottom-1.5 right-1.5 rounded bg-black/80 px-1.5 py-0.5 text-xs font-medium text-white tabular-nums"
                        @if ($video->watching_seconds !== $video->duration_seconds)
                            title="{{ __('Takes :time to watch here; the video is :length long.', ['time' => $video->watching_time_for_humans, 'length' => $video->duration_for_humans]) }}"
                        @endif
                    >
                        {{ $video->watching_time_for_humans }}
                        @if ($video->channel->effective_playback_rate !== 1.0)
                            <span class="text-white/60">· {{ $video->channel->effective_playback_rate }}×</span>
                        @endif
                    </span>
                @endif

                {{-- A record of where you got to, not a nudge to go back: no
                     label, no percentage, just the width of the bar. --}}
                @if ($video->percent_watched !== null && ! $video->isWatched())
                    <div class="absolute inset-x-0 bottom-0 h-1 bg-black/40">
                        <div
                            class="h-full bg-red-500"
                            style="width: {{ $video->percent_watched }}%"
                        ></div>
                    </div>
                @endif
            </div>
        </a>

        {{-- Over the picture rather than in a row of their own, so a card
             is no taller than what it shows and more of the grid fits. --}}
        <div class="absolute right-2 top-2 flex items-center gap-2 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100 touch:hidden">
            <button
                type="button"
                wire:click="toggleWatched"
                class="rounded-md bg-black/80 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-black"
            >
                {{ $video->isWatched() ? __('Mark unwatched') : __('Mark watched') }}
            </button>

            <button
                type="button"
                wire:click="hide"
                class="inline-flex items-center gap-1 rounded-md bg-black/80 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-black"
                title="{{ __('Hide from feed') }}"
            >
                <flux:icon.x-mark variant="micro" />
                {{ __('Hide') }}
            </button>

            @unless ($video->channel->isSnoozed())
                <button
                    type="button"
                    wire:click="snoozeChannel"
                    class="inline-flex items-center gap-1 rounded-md bg-black/80 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-black"
                    title="{{ __('Nothing new from :channel for :days days', ['channel' => $video->channel->display_name, 'days' => config('calm-tube.feed.snooze_days')]) }}"
                >
                    <flux:icon.moon variant="micro" />
                    {{ __('Snooze') }}
                </button>
            @endunless
        </div>
    </div>

    {{-- The same actions for fingers, which have no hover: always shown,
         each a 44px target. --}}
    <div class="-mr-2.5 mt-1 hidden items-center justify-end touch:flex">
        <button
            type="button"
            wire:click="toggleWatched"
            class="inline-flex size-11 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
            aria-label="{{ $video->isWatched() ? __('Mark unwatched') : __('Mark watched') }}"
        >
            @if ($video->isWatched())
                <flux:icon.check-circle variant="solid" class="size-6" />
            @else
                <flux:icon.check-circle class="size-6" />
            @endif
        </button>

        <button
            type="button"
            wire:click="hide"
            class="inline-flex size-11 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
            aria-label="{{ __('Hide from feed') }}"
        >
            <flux:icon.x-mark class="size-6" />
        </button>

        @unless ($video->channel->isSnoozed())
            <button
                type="button"
                wire:click="snoozeChannel"
                class="inline-flex size-11 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-zinc-500 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                aria-label="{{ __('Snooze :channel for :days days', ['channel' => $video->channel->display_name, 'days' => config('calm-tube.feed.snooze_days')]) }}"
            >
                <flux:icon.moon class="size-6" />
            </button>
        @endunless
    </div>

    <div class="mt-2 touch:mt-0 flex flex-1 flex-col {{ $video->isWatched() ? 'opacity-60' : '' }}">
        <a href="{{ route('videos.watch', $video) }}" wire:navigate>
            <flux:heading class="line-clamp-2 leading-snug">{{ $video->title }}</flux:heading>
        </a>

        <div class="mt-1.5 flex items-center gap-2">
            <a href="{{ route('channels.show', $video->channel) }}" wire:navigate class="shrink-0" tabindex="-1" aria-hidden="true">
                <img
                    src="{{ route('avatars.show', $video->channel) }}"
                    alt=""
                    loading="lazy"
                    class="size-5 rounded-full bg-zinc-200 object-cover dark:bg-zinc-700"
                />
            </a>

            <flux:text size="sm" class="truncate">
                <x-channel-link :channel="$video->channel" />
                · {{ $video->published_at->diffForHumans() }}
                @if ($video->isSnoozed())
                    · {{ __('snoozed') }}
                @elseif ($video->isSetAside())
                    · {{ __('set aside') }}
                @endif
                @if ($video->isWatched())
                    · {{ __('watched') }}
                @elseif ($video->isResumable())
                    · {{ __('stopped at') }} <span class="tabular-nums">{{ $video->resume_for_humans }}</span>
                @endif
            </flux:text>
        </div>
    </div>
</article>
