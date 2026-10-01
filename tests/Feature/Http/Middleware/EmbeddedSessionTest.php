<?php

use App\Http\Middleware\EmbeddedSession;
use App\Models\User;
use Symfony\Component\HttpFoundation\Cookie;

it('keeps the usual Lax session for a normal visit, and lets nobody else frame the page', function (): void {
    $response = $this->get(route('login'))->assertOk();

    $session = $response->getCookie(config('session.cookie'), false);
    expect($session)->not->toBeNull()
        ->and($session->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($session->isPartitioned())->toBeFalse()
        ->and($response->getCookie(EmbeddedSession::COOKIE, false))->toBeNull();

    $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
});

it('lets the configured origins frame the page', function (): void {
    config()->set('calm-tube.embed.origins', ['http://localhost:5173', 'http://localhost:4173']);

    $this->get(route('login'))
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' http://localhost:5173 http://localhost:4173");
});

it('gives a framed page its own partitioned session, CSRF cookie included', function (): void {
    $normal = config('session.cookie');
    $response = $this->withHeader('Sec-Fetch-Dest', 'iframe')->get(route('login'))->assertOk();

    expect($response->getCookie($normal, false))->toBeNull();

    foreach ([EmbeddedSession::COOKIE, 'XSRF-TOKEN'] as $name) {
        $cookie = $response->getCookie($name, false);
        expect($cookie)->not->toBeNull()
            ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_NONE)
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->isPartitioned())->toBeTrue();
    }
});

it('keeps requests from inside the frame on the framed session', function (): void {
    // Livewire's own requests are not frame navigations, but they carry the embedded cookie.
    $normal = config('session.cookie');
    $response = $this->withUnencryptedCookie(EmbeddedSession::COOKIE, 'not-a-real-session')->get(route('login'));

    expect($response->getCookie(EmbeddedSession::COOKIE, false)?->isPartitioned())->toBeTrue()
        ->and($response->getCookie($normal, false))->toBeNull();
});

it('remembers a login made inside the frame with a partitioned cookie', function (): void {
    $user = User::factory()->create();

    $response = $this->withHeader('Sec-Fetch-Dest', 'iframe')->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    $remember = collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => str_starts_with($cookie->getName(), 'remember_'));
    expect($remember)->not->toBeNull()
        ->and($remember->getSameSite())->toBe(Cookie::SAMESITE_NONE)
        ->and($remember->isPartitioned())->toBeTrue();
});
