<?php

use App\Enums\RefreshTrigger;
use App\Exceptions\YouTubeException;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Services\ChannelSampler;
use App\Services\YouTube\ChannelResolver;
use App\Services\YouTube\ImageArchiver;
use App\Support\RefreshResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Channels')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $input = '';

    public ?int $editingId = null;

    public bool $editOpen = false;

    public string $customName = '';

    public string $playbackRate = '';

    /** Uploads a day to keep from this channel; empty means all of them. */
    public string $sampleLimit = '';

    public ?int $deletingId = null;

    public bool $deleteOpen = false;

    public function addChannel(ChannelResolver $resolver): void
    {
        $this->validate();

        try {
            $channel = $resolver->resolve($this->input);
        } catch (YouTubeException $exception) {
            $this->addError('input', $exception->getMessage());

            return;
        } catch (ConnectionException) {
            $this->addError('input', "Couldn't reach YouTube. Check your connection and try again.");

            return;
        }

        $existing = Channel::query()
            ->where('youtube_channel_id', $channel->channelId)
            ->first();

        if ($existing !== null) {
            $this->addError('input', $existing->is_enabled
                ? "You already follow {$existing->display_name}."
                : "{$existing->display_name} is already added but disabled.");

            return;
        }

        $created = Channel::create([
            'youtube_channel_id' => $channel->channelId,
            'title' => $channel->title,
            'handle' => $channel->handle,
            'avatar_url' => $channel->avatarUrl,
            'uploads_playlist_id' => $channel->uploadsPlaylistId,
        ]);

        $this->input = '';

        $avatarPath = app(ImageArchiver::class)->archiveAvatar($created);

        if ($avatarPath !== null) {
            $created->forceFill(['avatar_path' => $avatarPath])->save();
        }

        $result = RefreshChannel::dispatchSync($created);
        $imported = $result instanceof RefreshResult ? $result->newVideos : 0;

        session()->flash('status', sprintf(
            'Added %s. %s imported.',
            $created->display_name,
            $imported === 1 ? '1 video' : "{$imported} videos"
        ));
    }

    /**
     * Disabling is the reversible half of removing a channel: nothing archived
     * is lost, the channel simply stops being refreshed and leaves the feed.
     */
    public function toggleEnabled(int $channelId): void
    {
        $channel = Channel::findOrFail($channelId);

        $channel->forceFill(['is_enabled' => ! $channel->is_enabled])->save();

        session()->flash('status', $channel->is_enabled
            ? "{$channel->display_name} is back in your feed."
            : "{$channel->display_name} is hidden from your feed. Nothing was deleted.");
    }

    public function edit(int $channelId): void
    {
        $channel = Channel::findOrFail($channelId);

        $this->editingId = $channel->id;
        $this->customName = $channel->custom_name ?? '';
        $this->playbackRate = $channel->playback_rate === null ? '' : (string) $channel->playback_rate;
        $this->sampleLimit = $channel->sample_limit === null ? '' : (string) $channel->sample_limit;
        $this->resetErrorBag();
        $this->editOpen = true;
    }

    public function save(ChannelSampler $sampler): void
    {
        $channel = Channel::findOrFail($this->editingId);

        /** @var list<float> $rates */
        $rates = config('calm-tube.player.playback_rates');

        $this->validate([
            'customName' => ['nullable', 'string', 'max:255'],
            'playbackRate' => [
                'nullable',
                Rule::in(array_map(fn (float $rate): string => (string) $rate, $rates)),
            ],
            'sampleLimit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // An emptied field means "go back to what YouTube calls it", not an
        // empty name, so it is stored as null rather than ''.
        $channel->forceFill([
            'custom_name' => $this->customName === '' ? null : $this->customName,
            'playback_rate' => $this->playbackRate === '' ? null : (float) $this->playbackRate,
            'sample_limit' => $this->sampleLimit === '' ? null : (int) $this->sampleLimit,
        ])->save();

        // Applied to what is already here, not only to the next refresh:
        // setting a limit and seeing the feed unchanged would read as broken.
        $setAside = $sampler->apply($channel->fresh());

        $this->editOpen = false;
        $this->editingId = null;

        session()->flash('status', $channel->isSampled() && $setAside > 0
            ? "Saved {$channel->display_name}. {$setAside} videos set aside."
            : "Saved {$channel->display_name}.");
    }

    public function confirmDelete(int $channelId): void
    {
        $this->deletingId = $channelId;
        $this->deleteOpen = true;
    }

    /**
     * The only destructive action in the app, and the only one that loses
     * archived metadata for good.
     */
    public function delete(int $channelId): void
    {
        $channel = Channel::findOrFail($channelId);
        $name = $channel->display_name;

        $channel->delete();

        $this->deleteOpen = false;
        $this->deletingId = null;

        session()->flash('status', "Deleted {$name} and everything archived with it.");
    }

    #[Computed]
    public function deletingChannel(): ?Channel
    {
        if ($this->deletingId === null) {
            return null;
        }

        return Channel::query()
            ->withCount([
                'videos',
                'videos as watched_videos_count' => fn ($query) => $query->whereNotNull('watched_at'),
            ])
            ->find($this->deletingId);
    }

    #[Computed]
    public function editingChannel(): ?Channel
    {
        return $this->editingId === null ? null : Channel::find($this->editingId);
    }

    public function refreshChannel(int $channelId): void
    {
        $channel = Channel::findOrFail($channelId);

        $result = RefreshChannel::dispatchSync($channel, RefreshTrigger::Manual);

        if (! $result instanceof RefreshResult) {
            return;
        }

        session()->flash('status', $result->isFailed()
            ? "{$channel->display_name}: {$result->errorMessage}"
            : sprintf(
                '%s: %s.',
                $channel->display_name,
                $result->newVideos === 0
                    ? 'no new videos'
                    : "{$result->newVideos} new ".($result->newVideos === 1 ? 'video' : 'videos')
            ));
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

        session()->flash('status', sprintf(
            '%s.%s',
            $new === 0 ? 'No new videos' : $new.' new '.($new === 1 ? 'video' : 'videos'),
            $failed === 0 ? '' : " {$failed} channel could not be refreshed."
        ));
    }

    /**
     * @return Collection<int, Channel>
     */
    public function with(): array
    {
        return [
            'channels' => Channel::query()
                ->withCount([
                    'videos',
                    'videos as set_aside_count' => fn ($query) => $query->setAside(),
                    // Counted the way the feed counts, so the number on the
                    // row is the number of cards you would actually see.
                    'videos as unwatched_count' => fn ($query) => $query->viewable()->unwatched(),
                ])
                ->orderBy('title')
                ->get(),
        ];
    }
}; ?>

<section class="mx-auto w-full max-w-4xl px-4 py-8">
    <div class="flex items-start justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Channels') }}</flux:heading>

        @if ($channels->isNotEmpty())
            <flux:button wire:click="refreshAll" icon="arrow-path" variant="subtle" class="data-loading:opacity-50">
                {{ __('Refresh all') }}
            </flux:button>
        @endif
    </div>

    @if (session('status'))
        <flux:callout variant="secondary" class="mt-4">{{ session('status') }}</flux:callout>
    @endif

    <div class="mt-6 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:heading size="lg">{{ __('Add a channel') }}</flux:heading>

        <form wire:submit="addChannel" class="mt-3 flex items-start gap-2">
            <flux:input
                wire:model="input"
                placeholder="{{ __('@handle, channel URL, or channel ID') }}"
                class="flex-1"
                data-calm-focus
            />

            <flux:button type="submit" variant="primary" class="data-loading:opacity-50">
                {{ __('Add') }}
            </flux:button>
        </form>

        @error('input')
            <flux:text class="mt-2 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror

        <flux:text size="sm" class="mt-2">
            {{ __('Paste any YouTube channel link, an @handle, or a link to one of its videos.') }}
        </flux:text>
    </div>

    @if ($channels->isEmpty())
        <flux:text class="mt-8 block text-center">
            {{ __('You are not following any channels yet.') }}
        </flux:text>
    @else
        <flux:text size="sm" class="mt-8 block">
            {{ $channels->count() }} {{ Str::plural('channel', $channels->count()) }} ·
            {{ $channels->where('is_enabled', true)->count() }} {{ __('enabled') }}
        </flux:text>

        <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($channels as $channel)
                <li class="flex items-center gap-3 p-4 sm:gap-4 {{ $channel->is_enabled ? '' : 'opacity-60' }}" wire:key="channel-{{ $channel->id }}">
                    <img
                        src="{{ route('avatars.show', $channel) }}"
                        alt=""
                        class="size-10 shrink-0 rounded-full bg-zinc-200 object-cover dark:bg-zinc-700"
                    />

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('channels.show', $channel) }}" wire:navigate class="hover:underline">
                            <flux:heading>{{ $channel->display_name }}</flux:heading>
                        </a>

                        <flux:text size="sm" class="truncate">
                            @if ($channel->unwatched_count > 0)
                                {{ $channel->unwatched_count.' '.__('unwatched') }}
                                · {{ $channel->videos_count }} {{ Str::plural('video', $channel->videos_count) }}
                            @else
                                {{ __('all caught up') }}
                                · {{ $channel->videos_count }} {{ Str::plural('video', $channel->videos_count) }}
                            @endif
                            @if ($channel->handle)
                                · {{ $channel->handle }}
                            @endif
                            @if ($channel->playback_rate !== null)
                                · {{ $channel->effective_playback_rate }}×
                            @endif
                            @if ($channel->isSampled())
                                · {{ __('keeping :n a day', ['n' => $channel->sample_limit]) }}
                                ({{ $channel->set_aside_count }} {{ __('set aside') }})
                            @endif
                            @unless ($channel->is_enabled)
                                · {{ __('disabled, hidden from your feed') }}
                            @endunless
                        </flux:text>

                        @if ($channel->hasRefreshError())
                            <flux:text size="sm" class="mt-1 block text-red-600 dark:text-red-400">
                                {{ __('Last refresh failed') }} — {{ $channel->last_refresh_error }}
                            </flux:text>
                        @elseif ($channel->last_refreshed_at)
                            <flux:text size="sm" class="mt-1 block">
                                {{ __('Refreshed') }} {{ $channel->last_refreshed_at->diffForHumans() }}
                            </flux:text>
                        @endif
                    </div>

                    <flux:button
                        wire:click="refreshChannel({{ $channel->id }})"
                        icon="arrow-path"
                        variant="subtle"
                        size="sm"
                        class="data-loading:opacity-50"
                    >
                        <span class="hidden sm:inline">{{ __('Refresh') }}</span>
                    </flux:button>

                    <flux:dropdown position="bottom" align="end">
                        <flux:button
                            icon="ellipsis-horizontal"
                            variant="subtle"
                            size="sm"
                            aria-label="{{ __('Channel options') }}"
                        />

                        <flux:menu>
                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $channel->id }})">
                                {{ __('Edit') }}
                            </flux:menu.item>

                            <flux:menu.item
                                :icon="$channel->is_enabled ? 'eye-slash' : 'eye'"
                                wire:click="toggleEnabled({{ $channel->id }})"
                            >
                                {{ $channel->is_enabled ? __('Disable') : __('Enable') }}
                            </flux:menu.item>

                            <flux:menu.separator />

                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $channel->id }})">
                                {{ __('Delete') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal wire:model.self="editOpen" class="w-full max-w-md">
        @if ($this->editingChannel)
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Edit') }} {{ $this->editingChannel->title }}</flux:heading>

                    <flux:text class="mt-1">
                        {{ __('Only how it appears here. Nothing is sent to YouTube.') }}
                    </flux:text>
                </div>

                <flux:input
                    wire:model="customName"
                    :label="__('Your name for it')"
                    :placeholder="$this->editingChannel->title"
                    :description="__('Leave it empty to use the name YouTube gives it.')"
                />

                <flux:select wire:model="playbackRate" :label="__('Playback speed')">
                    <flux:select.option value="">{{ __('Normal speed') }}</flux:select.option>

                    @foreach (config('calm-tube.player.playback_rates') as $rate)
                        @continue ($rate === 1.0)

                        <flux:select.option value="{{ $rate }}">{{ $rate }}×</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select
                    wire:model="sampleLimit"
                    :label="__('How much of this channel reaches your feed')"
                    :description="__('For channels that publish one real video and a pile of clips cut from it. The longest few of each day reach the feed; the rest stay on this channel\'s page. Nothing is deleted.')"
                >
                    <flux:select.option value="">{{ __('Everything it publishes') }}</flux:select.option>

                    @foreach ([1, 2, 3, 5, 10] as $limit)
                        <flux:select.option value="{{ $limit }}">
                            {{ __('The :n longest a day', ['n' => $limit]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                @error('sampleLimit')
                    <flux:text class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('editOpen', false)" variant="subtle">
                        {{ __('Cancel') }}
                    </flux:button>

                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="deleteOpen" class="w-full max-w-md">
        @if ($this->deletingChannel)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">
                        {{ __('Delete') }} {{ $this->deletingChannel->display_name }}?
                    </flux:heading>

                    {{-- The counts are composed in one expression so the
                         numbers and their nouns cannot be split across lines. --}}
                    <flux:text class="mt-2">
                        {{ __('This permanently removes') }}
                        <strong>{{ $this->deletingChannel->videos_count.' '.Str::plural('archived video', $this->deletingChannel->videos_count) }}</strong>,
                        {{ __('including') }}
                        <strong>{{ $this->deletingChannel->watched_videos_count.' '.__('marked as watched') }}</strong>,
                        {{ __('along with every archived title and thumbnail. It cannot be undone.') }}
                    </flux:text>

                    <flux:text class="mt-3">
                        {{ __('To stop seeing this channel without losing any of that, disable it instead.') }}
                    </flux:text>
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <flux:button wire:click="$set('deleteOpen', false)" variant="subtle">
                        {{ __('Cancel') }}
                    </flux:button>

                    @if ($this->deletingChannel->is_enabled)
                        <flux:button wire:click="toggleEnabled({{ $this->deletingChannel->id }})" variant="filled">
                            {{ __('Disable instead') }}
                        </flux:button>
                    @endif

                    <flux:button wire:click="delete({{ $this->deletingChannel->id }})" variant="danger">
                        {{ __('Delete permanently') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
