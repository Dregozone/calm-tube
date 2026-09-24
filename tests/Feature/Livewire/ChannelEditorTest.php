<?php

use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(calmUser());
});

it('opens on an edit-channel event from anywhere on the page', function (): void {
    $channel = calmChannel(['custom_name' => 'Bridges Guy']);

    Livewire::test('channel-editor')
        ->dispatch('edit-channel', channelId: $channel->id)
        ->assertSet('editOpen', true)
        ->assertSet('customName', 'Bridges Guy');
});

it('tells the page that opened it when a channel is saved', function (): void {
    $channel = calmChannel();

    Livewire::test('channel-editor')
        ->call('edit', $channel->id)
        ->set('customName', 'Renamed')
        ->call('save')
        ->assertDispatched('channel-saved')
        ->assertSet('editOpen', false);

    expect($channel->fresh()->custom_name)->toBe('Renamed');
});

it('is on the feed, so a channel can be edited from a card', function (): void {
    Livewire::test('pages::feed')->assertSeeLivewire('channel-editor');
});

it('is on the channel list', function (): void {
    Livewire::test('pages::channels.index')->assertSeeLivewire('channel-editor');
});
