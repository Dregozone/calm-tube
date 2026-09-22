<?php

use App\Models\Video;
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
}; ?>

<article class="group flex flex-col" wire:key="video-{{ $video->id }}">
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

            @if ($video->duration_for_humans)
                <span class="absolute bottom-1.5 right-1.5 rounded bg-black/80 px-1.5 py-0.5 text-xs font-medium text-white tabular-nums">
                    {{ $video->duration_for_humans }}
                </span>
            @endif
        </div>
    </a>

    <div class="mt-2.5 flex flex-1 flex-col {{ $video->isWatched() ? 'opacity-60' : '' }}">
        <a href="{{ route('videos.watch', $video) }}" wire:navigate>
            <flux:heading class="line-clamp-2 leading-snug">{{ $video->title }}</flux:heading>
        </a>

        <div class="mt-1.5 flex items-center gap-2">
            <img
                src="{{ route('avatars.show', $video->channel) }}"
                alt=""
                loading="lazy"
                class="size-5 shrink-0 rounded-full bg-zinc-200 object-cover dark:bg-zinc-700"
            />

            <flux:text size="sm" class="truncate">{{ $video->channel->display_name }}</flux:text>
        </div>

        <flux:text size="sm" class="mt-1">
            {{ $video->published_at->diffForHumans() }}
            @if ($video->isWatched())
                · {{ __('watched') }}
            @endif
        </flux:text>

        <div class="mt-2 flex items-center gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
            <flux:button wire:click="toggleWatched" size="xs" variant="subtle">
                {{ $video->isWatched() ? __('Mark unwatched') : __('Mark watched') }}
            </flux:button>

            <flux:button wire:click="hide" size="xs" variant="subtle" icon="x-mark" title="{{ __('Hide from feed') }}" />
        </div>
    </div>
</article>
