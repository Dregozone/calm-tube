<?php

use App\Models\User;

it('has no email verification screen', function (): void {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/email/verify')
        ->assertNotFound();
});

it('lets an unverified account into the verified-only settings', function (): void {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('appearance.edit'))
        ->assertOk();
});
