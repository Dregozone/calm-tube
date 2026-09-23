<?php

use App\Models\Video;
use App\Support\DescriptionRenderer;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Watch')] class extends Component
{
    public Video $video;

    /** The channel's playback speed as a form value; empty means normal. */
    public string $playbackRate = '';

    public function mount(): void
    {
        $this->playbackRate = $this->video->channel->playback_rate === null
            ? ''
            : (string) $this->video->channel->playback_rate;
    }

    public function markWatched(): void
    {
        if ($this->video->isWatched()) {
            return;
        }

        // Finished, so there is nothing left to resume.
        $this->video->forceFill(['watched_at' => now(), 'resume_seconds' => null])->save();
    }

    public function markUnwatched(): void
    {
        $this->video->forceFill(['watched_at' => null, 'resume_seconds' => null])->save();
    }

    /**
     * The embedded player forgets where you were between visits, so the
     * position is written here every few seconds while it plays.
     *
     * Renders nothing: the page is already showing the video, and a re-render
     * every few seconds for a number nothing on screen reads would be waste.
     */
    public function saveProgress(int $seconds): void
    {
        $this->skipRender();

        if ($seconds < 0) {
            return;
        }

        $this->video->forceFill(['resume_seconds' => $seconds])->save();
    }

    /**
     * Back to the beginning, and stay there.
     */
    public function clearProgress(): void
    {
        $this->video->forceFill(['resume_seconds' => null])->save();
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
     * Speed belongs to the speaker, not to one video, so it is stored on the
     * channel and applied to everything it publishes from here on.
     */
    public function updatedPlaybackRate(): void
    {
        /** @var list<float> $allowed */
        $allowed = config('calm-tube.player.playback_rates');

        $this->validateOnly('playbackRate', [
            'playbackRate' => [
                'nullable',
                Rule::in(array_map(fn (float $rate): string => (string) $rate, $allowed)),
            ],
        ]);

        $this->video->channel->forceFill([
            'playback_rate' => $this->playbackRate === '' ? null : (float) $this->playbackRate,
        ])->save();

        $this->dispatch('playback-rate-changed', rate: $this->video->channel->effective_playback_rate);
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
            'autoplay' => $this->shouldAutoplay() ? 1 : 0,
            'origin' => config('app.url'),
        ]);
    }

    /**
     * You chose this video, so it plays.
     *
     * Not the autoplay this app exists to avoid: that one chooses the next
     * video for you. A part-watched video is the exception, because the
     * resume prompt has a question to ask before anything starts.
     */
    public function shouldAutoplay(): bool
    {
        return (bool) config('calm-tube.player.autoplay')
            && ! $this->video->isResumable()
            && ! $this->video->isUnavailable();
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

<section class="w-full pb-12">
    <div class="mx-auto w-full max-w-7xl px-4 pt-6">
        <flux:button :href="route('feed')" wire:navigate variant="subtle" size="sm" icon="arrow-left">
            {{ __('Back to feed') }}
        </flux:button>
    </div>

    {{-- As large as the viewport allows while staying whole: the width is
         capped by the height left over, so the player never runs off screen. --}}
    <div
        class="mx-auto mt-4 w-full px-4"
        style="max-width: min(100%, calc((100dvh - 11rem) * 16 / 9 + 2rem));"
    >
        @if ($this->video->isUnavailable())
            <div class="flex aspect-video w-full flex-col items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">{{ __('This video is no longer available on YouTube.') }}</flux:heading>

                <flux:text class="mt-2">
                    {{ __('What was archived here is kept below.') }}
                </flux:text>
            </div>
        @else
            {{-- wire:ignore matters here. Marking the video watched re-renders
                 the component, and without it Livewire's morph would put the
                 panels back to hidden the instant the video ended. --}}
            <div
                wire:ignore
                id="calm-stage"
                class="relative aspect-video w-full overflow-hidden rounded-xl bg-black shadow-xl"
                data-return-url="{{ $this->returnUrl() }}"
                data-playback-rate="{{ $this->video->channel->effective_playback_rate }}"
                data-countdown-seconds="{{ config('calm-tube.player.countdown_seconds') }}"
                data-mask-seconds="{{ config('calm-tube.player.end_card_mask_seconds') }}"
                data-resume-seconds="{{ $this->video->isResumable() ? $this->video->resume_seconds : 0 }}"
                data-autoplay="{{ $this->shouldAutoplay() ? 1 : 0 }}"
            >
                <iframe
                    id="calm-player"
                    class="absolute inset-0 size-full"
                    src="{{ $this->embedUrl() }}"
                    title="{{ $this->video->title }}"
                    frameborder="0"
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                ></iframe>

                {{-- Offered, never applied behind your back: a video that
                     silently starts in the middle feels broken, and sometimes
                     you did mean to watch it again from the top. --}}
                @if ($this->video->isResumable())
                    <div
                        id="calm-resume"
                        class="absolute inset-x-0 bottom-0 z-20 flex flex-wrap items-center justify-center gap-3 bg-zinc-950/90 p-4 text-center"
                        data-resume-label="{{ $this->video->resume_for_humans }}"
                    >
                        <flux:text class="text-white">
                            {{ __('You stopped at') }}
                            <span class="tabular-nums">{{ $this->video->resume_for_humans }}</span>.
                        </flux:text>

                        <div class="flex items-center gap-2">
                            <flux:button id="calm-resume-go" variant="primary" size="sm">
                                {{ __('Resume') }}
                            </flux:button>

                            <flux:button id="calm-resume-restart" variant="subtle" size="sm" class="!text-white">
                                {{ __('Start again') }}
                            </flux:button>
                        </div>
                    </div>
                @endif

                {{-- Transparent, and only for the last seconds of the video:
                     it covers the end cards YouTube draws over the picture,
                     which no embed parameter can turn off. The control bar is
                     left exposed, and a click pauses rather than doing
                     nothing, so the one useful click on a video survives. --}}
                <div
                    id="calm-mask"
                    class="absolute inset-x-0 bottom-14 top-0 z-5 hidden cursor-pointer"
                    aria-hidden="true"
                ></div>

                {{-- In the DOM from the start: built when the video ends, it
                     would appear a beat after YouTube's own end screen. --}}
                <div
                    id="calm-ended"
                    class="absolute inset-0 z-10 hidden flex-col items-center justify-center gap-3 bg-zinc-950/95 p-8 text-center"
                >
                    <flux:heading size="lg" class="text-white">{{ __('Finished.') }}</flux:heading>

                    <div class="mt-2 flex flex-col items-center gap-2">
                        <flux:button id="calm-back" variant="primary" size="sm">
                            {{ __('Back to feed') }}
                        </flux:button>

                        @if ($this->nextUnwatched)
                            <flux:button
                                :href="route('videos.watch', $this->nextUnwatched)"
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
    </div>

    <div class="mx-auto mt-6 w-full max-w-5xl px-4">
        <flux:heading size="xl" level="1">{{ $this->video->title }}</flux:heading>

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

            <div class="flex items-center gap-2 sm:ms-auto">
                <flux:text size="sm" id="calm-speed-label">{{ __('Speed') }}</flux:text>

                <flux:select
                    wire:model.live="playbackRate"
                    size="sm"
                    class="max-w-40"
                    aria-labelledby="calm-speed-label"
                >
                    <flux:select.option value="">{{ __('Normal speed') }}</flux:select.option>

                    @foreach (config('calm-tube.player.playback_rates') as $rate)
                        @continue ($rate === 1.0)

                        <flux:select.option value="{{ $rate }}">{{ $rate }}×</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <flux:text size="sm" class="mt-2 block">
            @if ($this->video->channel->playback_rate !== null)
                {{ __('Every video from') }} {{ $this->video->channel->display_name }}
                {{ __('plays at') }} {{ $this->video->channel->effective_playback_rate }}×.
            @else
                {{ __('Speed applies to every video from this channel.') }}
            @endif
        </flux:text>

        @if ($this->video->isWatched())
            <flux:text size="sm" class="mt-2 block">
                {{ __('Watched') }} {{ $this->video->watched_at->diffForHumans() }}
            </flux:text>
        @endif

        {{-- Closed until asked for. A description is mostly links out —
             sponsors, socials, the author's other videos — and none of that
             should be in front of you while you are deciding what to watch. --}}
        @if ($this->video->description)
            <div x-data="{ expanded: false }" class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:button x-on:click="expanded = ! expanded" size="xs" variant="subtle">
                    <span x-text="expanded ? '{{ __('Hide description') }}' : '{{ __('Show description') }}'">{{ __('Show description') }}</span>
                </flux:button>

                <div
                    x-show="expanded"
                    x-cloak
                    class="mt-4 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300"
                >{!! $this->description() !!}</div>
            </div>
        @endif
    </div>

    {{-- Fixed to the viewport rather than to the player, so it is seen however
         far down the page you have scrolled, and ignored by Livewire so that
         marking the video watched cannot wipe it out mid-countdown. --}}
    <div
        wire:ignore
        id="calm-countdown"
        class="fixed inset-0 z-50 hidden items-center justify-center bg-zinc-950/60 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="calm-countdown-title"
    >
        <div class="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-8 text-center shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" id="calm-countdown-title">
                {{ __('Returning to your videos in') }}
            </flux:heading>

            <p class="mt-4 text-6xl font-semibold tabular-nums text-zinc-900 dark:text-white">
                <span id="calm-countdown-seconds">{{ config('calm-tube.player.countdown_seconds') }}</span>
            </p>

            <flux:text class="mt-4">
                {{ __('Stay on this page to keep the countdown from finishing.') }}
            </flux:text>

            <div class="mt-6 flex flex-col gap-2">
                <flux:button id="calm-go-now" variant="primary">
                    {{ __('Go now') }}
                </flux:button>

                <flux:button id="calm-stay" variant="subtle">
                    {{ __('Stay here') }}
                </flux:button>
            </div>
        </div>
    </div>
</section>

@script
<script>
    const stage = document.getElementById('calm-stage');
    const frame = document.getElementById('calm-player');
    const ended = document.getElementById('calm-ended');
    const errored = document.getElementById('calm-error');
    const modal = document.getElementById('calm-countdown');

    if (frame && stage) {
        const reveal = (panel) => panel?.classList.replace('hidden', 'flex');
        const conceal = (panel) => panel?.classList.replace('flex', 'hidden');

        const mask = document.getElementById('calm-mask');
        const returnUrl = stage.dataset.returnUrl;
        const total = Number(stage.dataset.countdownSeconds || 5);
        const maskFrom = Number(stage.dataset.maskSeconds || 0);
        const resumeAt = Number(stage.dataset.resumeSeconds || 0);
        const resumePanel = document.getElementById('calm-resume');
        const autoplay = stage.dataset.autoplay === '1';
        let rate = Number(stage.dataset.playbackRate || 1);
        let watching = null;
        let recording = null;

        let countdown = null;

        const stopCountdown = () => {
            clearTimeout(countdown);
            countdown = null;
            conceal(modal);
        };

        const leaveNow = () => {
            stopCountdown();
            window.location.assign(returnUrl);
        };

        const startCountdown = () => {
            const label = document.getElementById('calm-countdown-seconds');
            let remaining = total;

            reveal(modal);

            const tick = () => {
                if (label) {
                    label.textContent = remaining;
                }

                if (remaining <= 0) {
                    leaveNow();

                    return;
                }

                remaining--;
                countdown = setTimeout(tick, 1000);
            };

            tick();
        };

        // Every way out of the countdown other than letting it finish.
        document.getElementById('calm-stay')?.addEventListener('click', stopCountdown);
        document.getElementById('calm-go-now')?.addEventListener('click', leaveNow);
        document.getElementById('calm-back')?.addEventListener('click', leaveNow);
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) {
                stopCountdown();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && countdown !== null) {
                stopCountdown();
            }
        });

        const boot = () => {
            // Polled rather than scheduled: the remaining time moves with
            // seeking and with playback speed, and one check a second is
            // cheaper than getting either of those wrong.
            const watchForEndCards = () => {
                clearInterval(watching);

                if (maskFrom <= 0) {
                    return;
                }

                watching = setInterval(() => {
                    const left = player.getDuration() - player.getCurrentTime();

                    left > 0 && left <= maskFrom ? reveal(mask) : conceal(mask);
                }, 1000);
            };

            const stopWatching = () => {
                clearInterval(watching);
                watching = null;
                conceal(mask);
            };

            // Ten seconds is the most this can cost you, and it is the same
            // write every time, so it stays one row rather than a history.
            const recordProgress = () => {
                clearInterval(recording);

                recording = setInterval(() => {
                    $wire.saveProgress(Math.floor(player.getCurrentTime()));
                }, 10000);
            };

            const stopRecording = (save = true) => {
                clearInterval(recording);
                recording = null;

                if (save) {
                    $wire.saveProgress(Math.floor(player.getCurrentTime()));
                }
            };

            const applyRate = () => {
                if (rate !== 1) {
                    player.setPlaybackRate(rate);
                }
            };

            // The embed parameter asks; this asks again, because a browser
            // that blocked the first attempt may allow one made from a page
            // the viewer has already clicked on. If it refuses, the poster
            // stays up and the play button is where it always was.
            const start = () => {
                applyRate();

                if (autoplay) {
                    player.playVideo();
                }
            };

            const player = new YT.Player(frame, {
                events: {
                    onReady: start,
                    onStateChange(event) {
                        if (event.data === YT.PlayerState.PLAYING) {
                            conceal(ended);
                            conceal(resumePanel);
                            stopCountdown();
                            watchForEndCards();
                            recordProgress();
                            // YouTube resets the rate when a video actually
                            // starts, so asking once on ready is not enough.
                            applyRate();
                        }

                        if (event.data === YT.PlayerState.PAUSED) {
                            // Paused inside the masked stretch, the picture
                            // should be yours to look at.
                            stopWatching();
                            stopRecording();
                        }

                        if (event.data === YT.PlayerState.ENDED) {
                            // A fullscreen player would sit above the modal.
                            if (document.fullscreenElement) {
                                document.exitFullscreen?.();
                            }

                            stopWatching();
                            // markWatched clears the resume point, so this
                            // must not write one back after it.
                            stopRecording(false);
                            reveal(ended);
                            $wire.markWatched();
                            startCountdown();
                        }
                    },
                    onError(event) {
                        // 100 is removed or private; 101 and 150 are embedding
                        // disabled by the owner.
                        if (event.data === 100) {
                            $wire.markUnavailable();
                        }

                        stopCountdown();
                        stopWatching();
                        stopRecording(false);
                        reveal(errored);
                    },
                },
            });

            // Changing the speed applies to what you are watching right now,
            // not only to the next video from this channel.
            $wire.on('playback-rate-changed', (event) => {
                rate = Number(event.rate ?? 1);
                player.setPlaybackRate(rate);
            });

            // Clicking the picture is how you pause a video; the mask must
            // not take that away, only the end cards underneath it.
            mask?.addEventListener('click', () => player.pauseVideo());

            document.getElementById('calm-resume-go')?.addEventListener('click', () => {
                conceal(resumePanel);
                player.seekTo(resumeAt, true);
                player.playVideo();
            });

            document.getElementById('calm-resume-restart')?.addEventListener('click', () => {
                conceal(resumePanel);
                $wire.clearProgress();
                player.seekTo(0, true);
                player.playVideo();
            });

            // Closing the tab mid-video is the ordinary way to leave one.
            window.addEventListener('pagehide', () => stopRecording());

            document.getElementById('calm-replay')?.addEventListener('click', () => {
                stopCountdown();
                conceal(ended);
                $wire.clearProgress();
                player.seekTo(0);
                player.playVideo();
            });
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
