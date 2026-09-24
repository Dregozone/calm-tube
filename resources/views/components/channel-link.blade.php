@props(['channel'])

{{-- A channel's name as a link to its page, with a right-click menu to go
     there or edit the channel in place. Built on Alpine and styled like
     flux:menu, because flux:context is not part of Flux Free. The editor
     itself is the page's single <livewire:channel-editor />. --}}
<span
    x-data="{
        open: false,
        x: 0,
        y: 0,
        show(event) {
            this.x = Math.min(event.clientX, window.innerWidth - 208);
            this.y = Math.min(event.clientY, window.innerHeight - 96);
            this.open = true;
            this.$nextTick(() => this.$refs.menu.querySelector('[role=menuitem]')?.focus());
        },
    }"
>
    <a
        href="{{ route('channels.show', $channel) }}"
        wire:navigate
        x-on:contextmenu.prevent="show($event)"
        {{ $attributes->class('hover:underline') }}
    >{{ $channel->display_name }}</a>

    <template x-teleport="body">
        <div
            x-ref="menu"
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            x-on:click.outside="open = false"
            x-on:contextmenu.outside="open = false"
            x-on:keydown.escape.window="open = false"
            x-on:scroll.window="open = false"
            x-on:resize.window="open = false"
            x-bind:style="`left: ${x}px; top: ${y}px`"
            role="menu"
            aria-label="{{ $channel->display_name }}"
            class="fixed z-50 min-w-48 rounded-lg border border-zinc-200 bg-white p-[.3125rem] shadow-xs dark:border-zinc-600 dark:bg-zinc-700"
        >
            <a
                href="{{ route('channels.show', $channel) }}"
                wire:navigate
                role="menuitem"
                x-on:click="open = false"
                class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 select-none hover:bg-zinc-50 focus:bg-zinc-50 focus:outline-hidden dark:text-white dark:hover:bg-zinc-600 dark:focus:bg-zinc-600"
            >
                <flux:icon.arrow-right variant="mini" class="me-2 text-zinc-400 dark:text-white/60" />
                {{ __('Go to channel') }}
            </a>

            <button
                type="button"
                role="menuitem"
                x-on:click="open = false; $dispatch('edit-channel', { channelId: {{ $channel->id }} })"
                class="flex w-full items-center rounded-md px-2 py-1.5 text-start text-sm font-medium text-zinc-800 select-none hover:bg-zinc-50 focus:bg-zinc-50 focus:outline-hidden dark:text-white dark:hover:bg-zinc-600 dark:focus:bg-zinc-600"
            >
                <flux:icon.pencil-square variant="mini" class="me-2 text-zinc-400 dark:text-white/60" />
                {{ __('Edit') }}
            </button>
        </div>
    </template>
</span>
