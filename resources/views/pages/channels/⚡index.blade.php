<?php

use App\Enums\RefreshTrigger;
use App\Exceptions\YouTubeException;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Services\YouTube\ChannelResolver;
use App\Support\RefreshResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Channels')] class extends Component
{
    #[Validate('required|string|max:255')]
    public string $input = '';

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

        $result = RefreshChannel::dispatchSync($created);
        $imported = $result instanceof RefreshResult ? $result->newVideos : 0;

        session()->flash('status', sprintf(
            'Added %s. %s imported.',
            $created->display_name,
            $imported === 1 ? '1 video' : "{$imported} videos"
        ));
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
                ->withCount('videos')
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
                <li class="flex items-center gap-4 p-4 {{ $channel->is_enabled ? '' : 'opacity-60' }}" wire:key="channel-{{ $channel->id }}">
                    <div class="min-w-0 flex-1">
                        <flux:heading>{{ $channel->display_name }}</flux:heading>

                        <flux:text size="sm" class="truncate">
                            {{ $channel->videos_count }} {{ Str::plural('video', $channel->videos_count) }}
                            @if ($channel->handle)
                                · {{ $channel->handle }}
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
                        {{ __('Refresh') }}
                    </flux:button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
