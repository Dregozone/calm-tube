<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

it('has no password reset screens', function (string $uri): void {
    $this->get($uri)->assertNotFound();
})->with([
    'request a link' => '/forgot-password',
    'choose a password' => '/reset-password/some-token',
]);

it('does not send reset links', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();

    Notification::assertNothingSent();
});

it('does not offer a reset on the login page', function (): void {
    $this->get(route('login'))->assertDontSee('Forgot your password?');
});
