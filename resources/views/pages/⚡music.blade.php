<?php

use App\Exceptions\VideoLookupException;
use App\Exceptions\YouTubeException;
use App\Models\Mix;
use App\Services\YouTube\ChannelResolver;
use App\Services\YouTube\DataApiClient;
use App\Services\YouTube\ImageArchiver;
use App\Services\YouTube\OEmbedClient;
use App\Enums\ChannelInputType;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Music')] class extends Component
{
    #[Validate('required|string|max:500')]
    public string $url = '';

    public ?string $status = null;

    /**
     * Adds one video by its link.
     *
     * The title is archived once, here, like a video's; the duration comes
     * from the Data API when there is a key, and otherwise from the player the
     * first time the mix is played.
     */
    public function addMix(
        ChannelResolver $resolver,
        OEmbedClient $embeds,
        DataApiClient $api,
        ImageArchiver $archiver,
    ): void {
        $this->validate();
        $this->status = null;

        $videoId = $this->videoIdFrom($resolver, $this->url);

        if ($videoId === null) {
            $this->addError('url', __('That is not a link to a YouTube video. Paste a watch or youtu.be link.'));

            return;
        }

        $existing = Mix::query()->firstWhere('youtube_video_id', $videoId);

        if ($existing !== null) {
            $this->addError('url', __('Already in your music as ":title".', ['title' => $existing->title]));

            return;
        }

        try {
            $embed = $embeds->lookup($videoId);
        } catch (VideoLookupException $exception) {
            $this->addError('url', $exception->getMessage());

            return;
        }

        $mix = Mix::query()->create([
            'youtube_video_id' => $embed->videoId,
            'title' => $embed->title,
            'author_name' => $embed->authorName,
            'thumbnail_url' => $embed->thumbnailUrl,
        ]);

        $mix->forceFill([
            'thumbnail_path' => $archiver->archiveMixThumbnail($mix),
            'duration_seconds' => $this->durationOf($api, $videoId),
        ])->save();

        $this->reset('url');
        $this->status = __('Added ":title".', ['title' => $mix->title]);
    }

    public function removeMix(int $mixId): void
    {
        $mix = Mix::query()->find($mixId);

        if ($mix === null) {
            return;
        }

        $mix->delete();

        $this->status = __('Removed ":title".', ['title' => $mix->title]);
    }

    /**
     * Where you got to, written every few seconds while a mix plays, and the
     * length the first time the player can say what it is.
     *
     * Renders nothing: the player is already on screen and a re-render every
     * few seconds would be waste.
     */
    public function saveProgress(int $mixId, int $seconds, ?int $duration = null): void
    {
        $this->skipRender();

        $mix = Mix::query()->find($mixId);

        if ($mix === null || $seconds < 0) {
            return;
        }

        $mix->forceFill([
            'resume_seconds' => $seconds,
            'last_played_at' => now(),
            'duration_seconds' => $mix->duration_seconds ?? ($duration !== null && $duration > 0 ? $duration : null),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'mixes' => $this->mixes(),
        ];
    }

    /**
     * Newest first, like everything else here.
     *
     * @return Collection<int, Mix>
     */
    private function mixes(): Collection
    {
        return Mix::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * A bare id, or any link the channel form already understands as a
     * video: watch, youtu.be, embed, live and Shorts links alike.
     */
    private function videoIdFrom(ChannelResolver $resolver, string $input): ?string
    {
        $trimmed = trim($input);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $trimmed) === 1) {
            return $trimmed;
        }

        try {
            $parsed = $resolver->parse($trimmed);
        } catch (YouTubeException) {
            return null;
        }

        return $parsed->type === ChannelInputType::VideoId ? $parsed->value : null;
    }

    /**
     * Nice to know before the first play, never worth failing an add over.
     */
    private function durationOf(DataApiClient $api, string $videoId): ?int
    {
        try {
            return $api->videos([$videoId])->get($videoId)?->durationSeconds;
        } catch (YouTubeException) {
            return null;
        }
    }
}; ?>

<section class="w-full" id="calm-music">
    <flux:heading size="xl" level="1">{{ __('Music') }}</flux:heading>

    <flux:text class="mt-1">
        {{ __('Long mixes to put on in the background. They play right here, and never reach your feed.') }}
    </flux:text>

    <form wire:submit="addMix" class="mt-5 flex max-w-2xl items-start gap-2">
        <flux:input
            wire:model="url"
            placeholder="{{ __('Paste a YouTube video link') }}"
            class="flex-1"
            data-calm-focus
        />

        <flux:button type="submit" variant="primary" class="data-loading:opacity-50">
            {{ __('Add') }}
        </flux:button>
    </form>

    @error('url')
        <flux:text class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
    @enderror

    @if ($status)
        <flux:callout variant="secondary" class="mt-4 max-w-2xl">{{ $status }}</flux:callout>
    @endif

    @if ($mixes->isEmpty())
        <div class="mt-16 text-center">
            <flux:heading size="lg">{{ __('No music yet.') }}</flux:heading>

            <flux:text class="mt-2">
                {{ __('Paste a link to a long mix above to keep it here.') }}
            </flux:text>
        </div>
    @else
        <x-video-grid class="mt-6">
            @foreach ($mixes as $mix)
                <article
                    wire:key="mix-{{ $mix->id }}"
                    class="flex flex-col"
                    data-mix="{{ $mix->id }}"
                    data-video-id="{{ $mix->youtube_video_id }}"
                    data-resume="{{ $mix->resume_seconds ?? 0 }}"
                    data-duration="{{ $mix->duration_seconds ?? '' }}"
                >
                    {{-- Ignored by Livewire: the player lives in here once it
                         starts, and adding or removing another mix must not
                         morph it away mid-song. --}}
                    <div wire:ignore>
                        {{-- The player itself replaces the picture when it
                             plays. It stays visible, as YouTube requires of
                             an embedded player, and the mix's own picture is
                             what it shows anyway. --}}
                        <div class="relative aspect-video overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            <img
                                src="{{ route('mixes.thumbnail', $mix) }}"
                                alt=""
                                loading="lazy"
                                class="size-full object-cover"
                                data-poster
                            />

                            <button
                                type="button"
                                class="absolute inset-0 flex items-center justify-center bg-black/0 text-white transition hover:bg-black/30"
                                data-action="toggle"
                                data-cover
                                aria-label="{{ __('Play') }}"
                            >
                                <span class="rounded-full bg-black/70 p-3">
                                    <flux:icon.play variant="solid" class="size-6" />
                                </span>
                            </button>

                            <div class="absolute inset-0 hidden" data-frame></div>
                        </div>

                        <div class="mt-2 flex items-center gap-1">
                            <flux:button
                                type="button"
                                size="sm"
                                variant="subtle"
                                square
                                data-action="toggle"
                                aria-label="{{ __('Play or pause') }}"
                            >
                                <flux:icon.play variant="micro" data-icon="play" />
                                <flux:icon.pause variant="micro" data-icon="pause" class="hidden" />
                            </flux:button>

                            <flux:button
                                type="button"
                                size="sm"
                                variant="subtle"
                                square
                                data-action="restart"
                                title="{{ __('Start again') }}"
                                aria-label="{{ __('Start again') }}"
                            >
                                <flux:icon.arrow-uturn-left variant="micro" />
                            </flux:button>

                            <div
                                class="group/bar relative mx-1 flex h-4 flex-1 cursor-pointer items-center"
                                data-bar
                                title="{{ __('Jump to') }}"
                            >
                                <div class="h-1 w-full overflow-hidden rounded-full bg-zinc-200 transition-all group-hover/bar:h-1.5 dark:bg-zinc-700">
                                    <div
                                        class="h-full rounded-full bg-teal-500"
                                        style="width: {{ $mix->percent_played ?? 0 }}%"
                                        data-fill
                                    ></div>
                                </div>
                            </div>

                            <span class="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-zinc-400" data-time>
                                {{ collect([$mix->resume_for_humans, $mix->duration_for_humans])->filter()->join(' / ') }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-1.5 flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <flux:heading class="line-clamp-2 leading-snug">{{ $mix->title }}</flux:heading>

                            @if ($mix->author_name)
                                <flux:text size="sm" class="mt-0.5 truncate">{{ $mix->author_name }}</flux:text>
                            @endif
                        </div>

                        <flux:button
                            wire:click="removeMix({{ $mix->id }})"
                            wire:confirm="{{ __('Remove this mix from your music?') }}"
                            size="xs"
                            variant="subtle"
                            icon="x-mark"
                            title="{{ __('Remove') }}"
                            aria-label="{{ __('Remove') }}"
                        />
                    </div>
                </article>
            @endforeach
        </x-video-grid>
    @endif
</section>

@script
<script>
    const root = document.getElementById('calm-music');

    // One player for the whole page, moved to whichever mix you press play
    // on, so two mixes can never play over each other.
    let current = null;
    let ticking = null;
    let saving = null;

    const listening = new AbortController();
    const signal = listening.signal;

    const clock = (seconds) => {
        const total = Math.max(0, Math.floor(seconds));
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = String(total % 60).padStart(2, '0');

        return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    };

    const find = (card, part) => card.querySelector(`[data-${part}]`);

    const showPlaying = (card, playing) => {
        find(card, 'icon="play"')?.classList.toggle('hidden', playing);
        find(card, 'icon="pause"')?.classList.toggle('hidden', ! playing);
    };

    const paint = (card, at, length) => {
        const fill = find(card, 'fill');
        const time = find(card, 'time');

        if (fill && length > 0) {
            fill.style.width = `${Math.min(100, (at / length) * 100)}%`;
        }

        if (time) {
            time.textContent = length > 0 ? `${clock(at)} / ${clock(length)}` : clock(at);
        }
    };

    const loadApi = () => new Promise((resolve) => {
        if (window.YT && window.YT.Player) {
            resolve();

            return;
        }

        // The watch page may be waiting on the same script.
        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve();
        };

        if (! document.getElementById('youtube-iframe-api')) {
            const tag = document.createElement('script');
            tag.id = 'youtube-iframe-api';
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        }
    });

    const isReady = () => current?.player && typeof current.player.getCurrentTime === 'function';

    const save = () => {
        if (! isReady()) {
            return;
        }

        const at = Math.floor(current.player.getCurrentTime());
        const length = Math.round(current.player.getDuration()) || null;

        current.card.dataset.resume = at;

        if (length) {
            current.card.dataset.duration = length;
        }

        $wire.saveProgress(Number(current.card.dataset.mix), at, length);
    };

    // Puts the card back to its picture, with where you got to kept.
    const stop = () => {
        if (current === null) {
            return;
        }

        save();
        clearInterval(ticking);
        clearInterval(saving);

        try {
            current.player.destroy();
        } catch (error) {
            // Its card was removed from the page, and the player with it.
        }

        const { card } = current;

        find(card, 'frame')?.classList.add('hidden');
        find(card, 'cover')?.classList.remove('hidden');
        showPlaying(card, false);

        current = null;
    };

    const start = async (card, from) => {
        stop();
        await loadApi();

        const frame = find(card, 'frame');
        const target = document.createElement('div');

        frame.replaceChildren(target);
        frame.classList.remove('hidden');
        find(card, 'cover')?.classList.add('hidden');

        const session = { card, player: null };
        current = session;

        session.player = new YT.Player(target, {
            host: 'https://www.youtube-nocookie.com',
            videoId: card.dataset.videoId,
            width: '100%',
            height: '100%',
            playerVars: {
                autoplay: 1,
                start: Math.floor(from),
                rel: 0,
                modestbranding: 1,
                playsinline: 1,
                iv_load_policy: 3,
                origin: window.location.origin,
            },
            events: {
                onReady(event) {
                    event.target.playVideo();
                },
                onStateChange(event) {
                    if (current !== session) {
                        return;
                    }

                    const playing = event.data === YT.PlayerState.PLAYING;

                    showPlaying(card, playing);

                    clearInterval(ticking);
                    clearInterval(saving);

                    if (playing) {
                        ticking = setInterval(() => {
                            paint(card, session.player.getCurrentTime(), session.player.getDuration());
                        }, 500);

                        saving = setInterval(save, 10000);
                    }

                    if (event.data === YT.PlayerState.PAUSED) {
                        save();
                    }

                    // Background music that runs out is a silence you have
                    // to notice; the same mix goes round again instead.
                    if (event.data === YT.PlayerState.ENDED) {
                        session.player.seekTo(0, true);
                        session.player.playVideo();
                        $wire.saveProgress(Number(card.dataset.mix), 0, null);
                    }
                },
            },
        });
    };

    const toggle = (card) => {
        if (current?.card !== card) {
            start(card, Number(card.dataset.resume || 0));

            return;
        }

        if (! isReady()) {
            return;
        }

        current.player.getPlayerState() === YT.PlayerState.PLAYING
            ? current.player.pauseVideo()
            : current.player.playVideo();
    };

    const seek = (card, seconds) => {
        if (current?.card === card && isReady()) {
            current.player.seekTo(seconds, true);
            current.player.playVideo();
            paint(card, seconds, current.player.getDuration());

            return;
        }

        start(card, seconds);
    };

    root?.addEventListener('click', (event) => {
        const card = event.target.closest('[data-mix]');

        if (! card) {
            return;
        }

        const action = event.target.closest('[data-action]')?.dataset.action;

        if (action === 'toggle') {
            toggle(card);

            return;
        }

        if (action === 'restart') {
            seek(card, 0);
            $wire.saveProgress(Number(card.dataset.mix), 0, null);

            return;
        }

        const bar = event.target.closest('[data-bar]');
        const length = current?.card === card && isReady()
            ? current.player.getDuration()
            : Number(card.dataset.duration || 0);

        if (bar && length > 0) {
            const box = bar.getBoundingClientRect();
            const fraction = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));

            seek(card, fraction * length);
        }
    }, { signal });

    // Space plays or pauses whatever was playing last, as on the watch page.
    document.addEventListener('keydown', (event) => {
        const typing = event.target instanceof HTMLElement &&
            (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(event.target.tagName));

        if (event.key !== ' ' || typing || event.metaKey || event.ctrlKey || event.altKey || current === null) {
            return;
        }

        event.preventDefault();
        toggle(current.card);
    }, { signal });

    // Leaving the page stops the music, and keeps your place in it.
    window.addEventListener('pagehide', save, { signal });
    document.addEventListener('livewire:navigating', () => {
        stop();
        listening.abort();
    }, { once: true });
</script>
@endscript
