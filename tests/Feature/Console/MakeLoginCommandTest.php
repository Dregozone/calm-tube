<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a login that can sign in', function (): void {
    $this->artisan('make:login', ['email' => 'Me@Example.com', 'password' => 'a-good-password'])
        ->expectsOutputToContain('Created a login for me@example.com')
        ->assertSuccessful();

    $user = User::query()->sole();
    expect($user->email)->toBe('me@example.com')
        ->and($user->name)->toBe('me')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-good-password', $user->password))->toBeTrue();

    $this->post(route('login.store'), ['email' => 'me@example.com', 'password' => 'a-good-password']);
    $this->assertAuthenticatedAs($user);
});

it('takes a name when given one', function (): void {
    $this->artisan('make:login', ['email' => 'me@example.com', 'password' => 'a-good-password', '--name' => 'Andreas'])
        ->assertSuccessful();

    expect(User::query()->sole()->name)->toBe('Andreas');
});

it('prompts for the password when it is left off', function (): void {
    $this->artisan('make:login', ['email' => 'me@example.com'])
        ->expectsQuestion('Password', 'a-good-password')
        ->expectsQuestion('Confirm password', 'a-good-password')
        ->assertSuccessful();

    expect(Hash::check('a-good-password', User::query()->sole()->password))->toBeTrue();
});

it('refuses a prompted password that was not typed the same twice', function (): void {
    $this->artisan('make:login', ['email' => 'me@example.com'])
        ->expectsQuestion('Password', 'a-good-password')
        ->expectsQuestion('Confirm password', 'a-good-pasword')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('resets the password of a login that already exists', function (): void {
    $user = User::factory()->create(['email' => 'me@example.com', 'name' => 'Andreas']);

    $this->artisan('make:login', ['email' => 'me@example.com', 'password' => 'a-new-password'])
        ->expectsOutputToContain('Password updated for me@example.com')
        ->assertSuccessful();

    $user->refresh();
    expect(User::query()->count())->toBe(1)
        ->and($user->name)->toBe('Andreas')
        ->and(Hash::check('a-new-password', $user->password))->toBeTrue();
});

it('refuses an invalid email or a weak password', function (string $email, string $password): void {
    $this->artisan('make:login', ['email' => $email, 'password' => $password])->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'not an email' => ['not-an-email', 'a-good-password'],
    'too short' => ['me@example.com', 'short'],
]);
