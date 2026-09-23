<?php

use App\Models\Channel;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

it('ships the shortcut handler on every page', function (string $route): void {
    $this->get($route)
        ->assertSee('calmKeyboardBound', escape: false)
        ->assertSee('Livewire.navigate', escape: false);
})->with([
    'the feed' => fn (): string => route('feed'),
    'the channel list' => fn (): string => route('channels.index'),
]);

it('binds the handler once, however many pages you visit', function (): void {
    // wire:navigate keeps the document, so a second set of listeners would
    // fire every shortcut twice.
    $this->get(route('feed'))->assertSee('window.calmKeyboardBound = true', escape: false);
});

it('knows where g f and g c should go', function (): void {
    $this->get(route('feed'))
        ->assertSee(route('feed'), escape: false)
        ->assertSee(route('channels.index'), escape: false);
});

it('gives the feed a focus target for the slash key', function (): void {
    calmChannel();

    $this->get(route('feed'))->assertSee('data-calm-focus', escape: false);
});

it('gives the channel list a focus target for the slash key', function (): void {
    Channel::factory()->create();

    $this->get(route('channels.index'))->assertSee('data-calm-focus', escape: false);
});

it('does not offer card-by-card navigation, which is a speed feature', function (): void {
    $this->get(route('feed'))->assertDontSee('ArrowDown', escape: false);
});
