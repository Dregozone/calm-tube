{{--
    Three shortcuts, and deliberately no more. There is no j/k card-by-card
    navigation: that is a scrolling-speed feature, and speed is not the goal.
    The watch page adds its own player keys on top of these.

    Attached to the document, which survives wire:navigate, so the guard stops
    a second visit stacking another set of listeners.
--}}
<script data-navigate-once>
    if (! window.calmKeyboardBound) {
        window.calmKeyboardBound = true;

        const isTyping = (element) =>
            element instanceof HTMLElement &&
            (element.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName));

        let awaitingGo = null;

        document.addEventListener('keydown', (event) => {
            if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target)) {
                return;
            }

            // "g" then a destination, the pair timing out so a stray press
            // cannot hijack whatever you type a minute later.
            if (awaitingGo !== null) {
                clearTimeout(awaitingGo);
                awaitingGo = null;

                // The key after "g" belongs to the pair, so the "f" in "g f"
                // cannot also toggle the watch page's fullscreen.
                event.stopImmediatePropagation();

                const destination = {
                    f: @js(route('feed')),
                    c: @js(route('channels.index')),
                }[event.key];

                if (destination) {
                    event.preventDefault();
                    window.Livewire ? Livewire.navigate(destination) : window.location.assign(destination);
                }

                return;
            }

            if (event.key === 'g') {
                awaitingGo = setTimeout(() => (awaitingGo = null), 1500);

                return;
            }

            // The list below, so nothing depends on remembering the README.
            if (event.key === '?') {
                event.preventDefault();
                window.Flux?.modal('calm-shortcuts').show();

                return;
            }

            // Whatever this page's one text control is: the channel filter on
            // the feed, the add form on the channels page.
            if (event.key === '/') {
                const target = document.querySelector('[data-calm-focus]');

                if (target instanceof HTMLElement) {
                    event.preventDefault();
                    target.focus();
                }
            }
        });
    }
</script>

{{-- Rendered with each page, so the player keys are listed only where they
     work. --}}
@php
    $shortcutGroups = [
        __('Anywhere') => [
            ['g', 'f', __('Go to the feed')],
            ['g', 'c', __('Go to channels')],
            ['/', null, __('Focus the channel filter, or the add form')],
            ['?', null, __('Show this list')],
            ['Esc', null, __('Close a modal, or stop the countdown')],
        ],
    ];

    if (request()->routeIs('videos.watch')) {
        $shortcutGroups[__('Watching')] = [
            ['Space', null, __('Play or pause (or resume)')],
            ['k', null, __('Play or pause')],
            ['←', null, __('Back :n seconds', ['n' => config('calm-tube.player.seek_seconds')])],
            ['→', null, __('Forward :n seconds', ['n' => config('calm-tube.player.seek_seconds')])],
            ['f', null, __('Fullscreen')],
            ['m', null, __('Mute')],
        ];
    }
@endphp

<flux:modal name="calm-shortcuts" class="w-full max-w-md">
    <flux:heading size="lg">{{ __('Keyboard shortcuts') }}</flux:heading>

    @foreach ($shortcutGroups as $group => $shortcuts)
        <flux:heading size="sm" class="mt-6">{{ $group }}</flux:heading>

        <dl class="mt-2 space-y-2">
            @foreach ($shortcuts as [$first, $then, $does])
                <div class="flex items-center justify-between gap-4">
                    <dt class="flex items-center gap-1 text-sm">
                        <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-800">{{ $first }}</kbd>

                        @if ($then)
                            <span class="text-xs text-zinc-500">{{ __('then') }}</span>
                            <kbd class="rounded border border-zinc-300 bg-zinc-100 px-1.5 py-0.5 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-800">{{ $then }}</kbd>
                        @endif
                    </dt>

                    <dd class="text-end text-sm text-zinc-600 dark:text-zinc-300">{{ $does }}</dd>
                </div>
            @endforeach
        </dl>
    @endforeach
</flux:modal>
