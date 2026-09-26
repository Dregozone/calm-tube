<?php

use App\Models\User;

it('has no registration screen', function (): void {
    $this->get('/register')->assertNotFound();
});

it('does not let anyone sign up', function (): void {
    $this->post('/register', [
        'name' => 'Stranger',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('does not offer sign up on the login page', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up');
});
