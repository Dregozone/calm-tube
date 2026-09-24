<?php

use App\Enums\SamplePeriod;
use App\Models\Channel;
use App\Services\ChannelSampler;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The per-channel settings modal, opened from anywhere with an edit-channel
 * event: the channel list's menu, or right-clicking a channel on a card.
 *
 * One instance per page rather than one per card, so a grid of videos does
 * not carry a grid of hidden forms.
 */
new class extends Component
{
    public ?int $editingId = null;

    public bool $editOpen = false;

    public string $customName = '';

    public string $playbackRate = '';

    /** Seconds of sponsor plug at the end of every video; empty means none. */
    public string $outroSeconds = '';

    /** Uploads a day or week to keep from this channel; empty means all of them. */
    public string $sampleLimit = '';

    /** day | week */
    public string $samplePeriod = 'day';

    /** What you want from the channel, for the weekly pick to judge against. */
    public string $sampleNote = '';

    #[On('edit-channel')]
    public function edit(int $channelId): void
    {
        $channel = Channel::findOrFail($channelId);

        $this->editingId = $channel->id;
        $this->customName = $channel->custom_name ?? '';
        $this->playbackRate = $channel->playback_rate === null ? '' : (string) $channel->playback_rate;
        $this->outroSeconds = $channel->outro_seconds === null ? '' : (string) $channel->outro_seconds;
        $this->sampleLimit = $channel->sample_limit === null ? '' : (string) $channel->sample_limit;
        $this->samplePeriod = $channel->sample_period->value;
        $this->sampleNote = $channel->sample_note ?? '';
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
            'outroSeconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'sampleLimit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'samplePeriod' => ['required', Rule::enum(SamplePeriod::class)],
            'sampleNote' => ['nullable', 'string', 'max:2000'],
        ]);

        // An emptied field means "go back to what YouTube calls it", not an
        // empty name, so it is stored as null rather than ''.
        $channel->forceFill([
            'custom_name' => $this->customName === '' ? null : $this->customName,
            'playback_rate' => $this->playbackRate === '' ? null : (float) $this->playbackRate,
            'outro_seconds' => $this->outroSeconds === '' ? null : (int) $this->outroSeconds,
            'sample_limit' => $this->sampleLimit === '' ? null : (int) $this->sampleLimit,
            'sample_period' => SamplePeriod::from($this->samplePeriod),
            'sample_note' => trim($this->sampleNote) === '' ? null : trim($this->sampleNote),
        ])->save();

        // Applied to what is already here, not only to the next refresh:
        // setting a limit and seeing the feed unchanged would read as broken.
        $setAside = $sampler->apply($channel->fresh());

        $this->editOpen = false;
        $this->editingId = null;

        // Whichever page opened this re-renders with the new settings.
        $this->dispatch('channel-saved');

        Flux::toast(text: $channel->isSampled() && $setAside > 0
            ? "Saved {$channel->display_name}. {$setAside} videos set aside."
            : "Saved {$channel->display_name}.");
    }

    #[Computed]
    public function editingChannel(): ?Channel
    {
        return $this->editingId === null ? null : Channel::find($this->editingId);
    }
}; ?>

<div>
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

                <flux:input
                    wire:model="outroSeconds"
                    type="number"
                    min="1"
                    max="120"
                    :label="__('Finish early (seconds)')"
                    placeholder="15"
                    :description="__('For channels that end every video with the same sponsor plug. The video counts as finished this many seconds before the end, and you are sent back to your list. Leave it empty to watch to the end.')"
                />

                <flux:select
                    wire:model.live="sampleLimit"
                    :label="__('How much of this channel reaches your feed')"
                    :description="__('For channels that publish far more than you want to watch. The rest stay on the channel page. Nothing is deleted.')"
                >
                    <flux:select.option value="">{{ __('Everything it publishes') }}</flux:select.option>

                    @foreach ([1, 2, 3, 5, 10] as $limit)
                        <flux:select.option value="{{ $limit }}">{{ $limit }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($sampleLimit !== '')
                    <flux:radio.group wire:model.live="samplePeriod" :label="__('Counted')">
                        <flux:radio
                            value="day"
                            :label="__('A day, keeping the longest')"
                            :description="__('For channels that publish one real video and a pile of clips cut from it.')"
                        />
                        <flux:radio
                            value="week"
                            :label="__('A week, picked for you')"
                            :description="__('Uploads wait until the week is over, then a local AI model picks the ones most worth your time. It can pick fewer, or none.')"
                        />
                    </flux:radio.group>

                    @if ($samplePeriod === 'week')
                        <flux:textarea
                            wire:model="sampleNote"
                            rows="3"
                            :label="__('What you want from this channel')"
                            :placeholder="__('Long-form teaching on hiring, offers and pricing. Skip motivational one-liners.')"
                            :description="__('The pick also learns from what you finish, abandon and hide here.')"
                        />
                    @endif
                @endif

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
</div>
