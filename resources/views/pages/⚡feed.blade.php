<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Feed')] class extends Component
{
    //
}; ?>

<section class="mx-auto w-full max-w-7xl px-4 py-8">
    <flux:heading size="xl" level="1">{{ __('Feed') }}</flux:heading>

    <flux:text class="mt-2">
        {{ __('Videos from the channels you follow will appear here.') }}
    </flux:text>
</section>
