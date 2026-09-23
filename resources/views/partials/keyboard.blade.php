{{--
    Three shortcuts, and deliberately no more. There is no j/k card-by-card
    navigation: that is a scrolling-speed feature, and speed is not the goal.

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
