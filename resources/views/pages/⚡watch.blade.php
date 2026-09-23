<?php

use App\Models\Video;
use App\Support\DescriptionRenderer;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Watch')] class extends Component
{
    public Video $video;

    public function markWatched(): void
    {
        if ($this->video->isWatched()) {
            return;
        }

        $this->video->forceFill(['watched_at' => now()])->save();
    }

    public function markUnwatched(): void
    {
        $this->video->forceFill(['watched_at' => null])->save();
    }

    public function toggleWatched(): void
    {
        $this->video->isWatched()
            ? $this->markUnwatched()
            : $this->markWatched();
    }

    /**
     * The player reports a video YouTube no longer has, which is the earliest
     * signal available; the nightly sweep would otherwise find it eventually.
     */
    public function markUnavailable(): void
    {
        $this->video->forceFill(['unavailable_at' => now()])->save();
    }

    /**
     * Deliberately a link and never an autoplay. One explicit next video is
     * the only thing this app ever suggests.
     */
    #[Computed]
    public function nextUnwatched(): ?Video
    {
        return Video::query()
            ->inFeed()
            ->unwatched()
            ->where('channel_id', $this->video->channel_id)
            ->whereKeyNot($this->video->getKey())
            ->orderByDesc('published_at')
            ->first();
    }

    public function embedUrl(): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$this->video->youtube_video_id.'?'.http_build_query([
            // Since 2018 this limits end-screen suggestions to the same
            // channel rather than removing them, which is why the panel below
            // covers the end screen outright.
            'rel' => 0,
            'modestbranding' => 1,
            'playsinline' => 1,
            'iv_load_policy' => 3,
            'enablejsapi' => 1,
            'autoplay' => 0,
            'origin' => config('app.url'),
        ]);
    }

    /**
     * Back to the list you came from, filtered the way you left it, as a full
     * page visit so a video just marked watched is gone from an unwatched
     * list rather than lingering in a cached grid.
     */
    public function returnUrl(): string
    {
        $filter = (string) session('calm-tube.feed.filter', 'all');
        $channel = (string) session('calm-tube.feed.channel', '');

        return route('feed', array_filter([
            'filter' => $filter === 'all' ? null : $filter,
            'channel' => $channel === '' ? null : $channel,
        ]));
    }

    public function watchOnYouTubeUrl(): string
    {
        return 'https://www.youtube.com/watch?v='.$this->video->youtube_video_id;
    }

    public function description(): HtmlString
    {
        return app(DescriptionRenderer::class)->toHtml($this->video->description);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl px-4 py-8">
    <flux:button :href="route('feed')" wire:navigate variant="subtle" size="sm" icon="arrow-left">
        {{ __('Back to feed') }}
    </flux:button>

    @if ($this->video->isUnavailable())
        <div class="mt-6 flex aspect-video w-full flex-col items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">{{ __('This video is no longer available on YouTube.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('What was archived here is kept below.') }}
            </flux:text>
        </div>
    @else
        <div class="relative mt-6 aspect-video w-full overflow-hidden rounded-xl bg-black">
            <iframe
                id="calm-player"
                class="absolute inset-0 size-full"
                src="{{ $this->embedUrl() }}"
                title="{{ $this->video->title }}"
                frameborder="0"
                allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
            ></iframe>

            {{-- In the DOM from the start: built when the video ends, it would
                 appear a beat after YouTube's own end screen. --}}
            <div
                id="calm-ended"
                class="absolute inset-0 z-10 hidden flex-col items-center justify-center gap-3 bg-zinc-950/95 p-8 text-center"
                data-return-url="{{ $this->returnUrl() }}"
            >
                <flux:heading size="lg" class="text-white">{{ __('Finished.') }}</flux:heading>

                {{-- Leaving is automatic, arriving somewhere new never is. Any
                     button below stops the countdown. --}}
                <flux:text id="calm-countdown" class="text-white/70">
                    {{ __('Returning to your videos in') }} <span id="calm-countdown-seconds">3</span>…
                </flux:text>

                <div class="mt-2 flex flex-col items-center gap-2">
                    <flux:button :href="route('feed')" wire:navigate variant="primary" size="sm">
                        {{ __('Back to feed') }}
                    </flux:button>

                    @if ($this->nextUnwatched)
                        <flux:button
                            :href="route('videos.watch', $this->nextUnwatched)"
                            wire:navigate
                            variant="subtle"
                            size="sm"
                            class="!text-white"
                        >
                            <span class="line-clamp-1">
                                {{ __('Next unwatched') }}: {{ $this->nextUnwatched->title }}
                            </span>
                        </flux:button>
                    @endif

                    <flux:button id="calm-replay" variant="subtle" size="sm" class="!text-white">
                        {{ __('Replay') }}
                    </flux:button>

                    <flux:button id="calm-stay" variant="ghost" size="xs" class="!text-white/60">
                        {{ __('Stay here') }}
                    </flux:button>
                </div>
            </div>

            <div
                id="calm-error"
                class="absolute inset-0 z-10 hidden flex-col items-center justify-center gap-3 bg-zinc-950/95 p-8 text-center"
            >
                <flux:heading size="lg" class="text-white">
                    {{ __("This video can't be played here.") }}
                </flux:heading>

                <flux:button href="{{ $this->watchOnYouTubeUrl() }}" target="_blank" rel="noopener noreferrer" variant="primary" size="sm">
                    {{ __('Open on YouTube') }}
                </flux:button>
            </div>
        </div>
    @endif

    <flux:heading size="xl" level="1" class="mt-6">{{ $this->video->title }}</flux:heading>

    <div class="mt-2 flex flex-wrap items-center gap-2">
        <img
            src="{{ route('avatars.show', $this->video->channel) }}"
            alt=""
            class="size-6 rounded-full bg-zinc-200 object-cover dark:bg-zinc-700"
        />

        <flux:text>{{ $this->video->channel->display_name }}</flux:text>
        <flux:text>·</flux:text>
        <flux:text>{{ $this->video->published_at->diffForHumans() }}</flux:text>

        @if ($this->video->duration_for_humans)
            <flux:text>·</flux:text>
            <flux:text class="tabular-nums">{{ $this->video->duration_for_humans }}</flux:text>
        @endif

        @if ($this->video->live_status !== App\Enums\LiveStatus::None && $this->video->scheduled_start_at)
            <flux:text>·</flux:text>
            <flux:text>{{ __('Scheduled for') }} {{ $this->video->scheduled_start_at->toFormattedDateString() }}</flux:text>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <flux:button wire:click="toggleWatched" size="sm" variant="subtle">
            {{ $this->video->isWatched() ? __('Mark as unwatched') : __('Mark as watched') }}
        </flux:button>

        <a
            href="{{ $this->watchOnYouTubeUrl() }}"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white"
        >
            {{ __('Open on YouTube') }}
            <flux:icon.arrow-top-right-on-square variant="micro" />
        </a>
    </div>

    @if ($this->video->isWatched())
        <flux:text size="sm" class="mt-2 block">
            {{ __('Watched') }} {{ $this->video->watched_at->diffForHumans() }}
        </flux:text>
    @endif

    @if ($this->video->description)
        <div x-data="{ expanded: false }" class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <div
                class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300"
                :class="expanded || 'line-clamp-6'"
            >{!! $this->description() !!}</div>

            <flux:button x-on:click="expanded = ! expanded" size="xs" variant="subtle" class="mt-2">
                <span x-text="expanded ? '{{ __('Show less') }}' : '{{ __('Show more') }}'">{{ __('Show more') }}</span>
            </flux:button>
        </div>
    @endif
</section>

@script
<script>
    const frame = document.getElementById('calm-player');
    const ended = document.getElementById('calm-ended');
    const errored = document.getElementById('calm-error');

    if (frame) {
        const reveal = (panel) => panel?.classList.replace('hidden', 'flex');
        const conceal = (panel) => panel?.classList.replace('flex', 'hidden');

        let countdown = null;

        const stopCountdown = () => {
            clearTimeout(countdown);
            countdown = null;
            document.getElementById('calm-countdown')?.classList.add('hidden');
        };

        const startCountdown = () => {
            const label = document.getElementById('calm-countdown-seconds');
            const returnUrl = ended?.dataset.returnUrl;
            let remaining = 3;

            const tick = () => {
                if (label) {
                    label.textContent = remaining;
                }

                if (remaining <= 0) {
                    window.location.assign(returnUrl);

                    return;
                }

                remaining--;
                countdown = setTimeout(tick, 1000);
            };

            tick();
        };

        // Choosing anything else is a decision to stay.
        ended?.addEventListener('click', stopCountdown);

        const boot = () => {
            const player = new YT.Player(frame, {
                events: {
                    onStateChange(event) {
                        if (event.data === YT.PlayerState.ENDED) {
                            reveal(ended);
                            $wire.markWatched();
                            startCountdown();
                        }

                        if (event.data === YT.PlayerState.PLAYING) {
                            conceal(ended);
                            stopCountdown();
                        }
                    },
                    onError(event) {
                        // 100 is removed or private; 101 and 150 are embedding
                        // disabled by the owner.
                        if (event.data === 100) {
                            $wire.markUnavailable();
                        }

                        reveal(errored);
                    },
                },
            });

            document.getElementById('calm-replay')?.addEventListener('click', () => {
                stopCountdown();
                conceal(ended);
                player.seekTo(0);
                player.playVideo();
            });

            document.getElementById('calm-stay')?.addEventListener('click', stopCountdown);
        };

        if (window.YT && window.YT.Player) {
            boot();
        } else {
            // The API script itself must come from youtube.com even though the
            // player is hosted on youtube-nocookie.com.
            window.onYouTubeIframeAPIReady = boot;

            if (! document.getElementById('youtube-iframe-api')) {
                const tag = document.createElement('script');
                tag.id = 'youtube-iframe-api';
                tag.src = 'https://www.youtube.com/iframe_api';
                document.head.appendChild(tag);
            }
        }
    }
</script>
@endscript
