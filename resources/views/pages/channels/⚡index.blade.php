<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Channels')] class extends Component
{
    //
}; ?>

<section class="mx-auto w-full max-w-7xl px-4 py-8">
    <flux:heading size="xl" level="1">{{ __('Channels') }}</flux:heading>

    <flux:text class="mt-2">
        {{ __('The channels you follow will be managed here.') }}
    </flux:text>
</section>
