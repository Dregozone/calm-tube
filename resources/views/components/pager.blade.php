@props([
    'paginator',
])

{{-- Only when there is somewhere to go. --}}
@if ($paginator->hasPages())
    <div {{ $attributes->class('flex flex-col items-center gap-3') }}>
        <flux:text size="sm">
            {{ __('Showing') }} {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            {{ __('of') }} {{ $paginator->total() }}
        </flux:text>

        <div class="flex items-center gap-2">
            <flux:button
                wire:click="previousPage"
                :disabled="$paginator->onFirstPage()"
                variant="subtle"
                size="sm"
                icon="arrow-left"
            >
                {{ __('Previous') }}
            </flux:button>

            <flux:button
                wire:click="nextPage"
                :disabled="! $paginator->hasMorePages()"
                variant="subtle"
                size="sm"
                icon:trailing="arrow-right"
            >
                {{ __('Next') }}
            </flux:button>
        </div>
    </div>
@endif
