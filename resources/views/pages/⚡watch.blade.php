<?php

use App\Models\Video;
use Livewire\Component;

new class extends Component
{
    public Video $video;
}; ?>

<section class="mx-auto w-full max-w-4xl px-4 py-8">
    <flux:button :href="route('feed')" wire:navigate variant="subtle" size="sm" icon="arrow-left">
        {{ __('Back to feed') }}
    </flux:button>

    <flux:heading size="xl" level="1" class="mt-6">{{ $video->title }}</flux:heading>

    <flux:text class="mt-2">{{ $video->channel->display_name }}</flux:text>
</section>
