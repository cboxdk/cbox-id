<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('bounces an unauthenticated request to login and remembers where it was headed', function (): void {
    // A user sent to a protected route (e.g. mid /oauth/authorize) is returned to
    // login, but the intended URL is stashed so login can resume it afterwards.
    $this->get('/dashboard')->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toContain('/dashboard');
});

it('preserves an authorize request as the intended url', function (): void {
    $this->get('/oauth/authorize?client_id=abc&redirect_uri=https://app.test/cb&response_type=code&code_challenge=xyz&code_challenge_method=S256')
        ->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toContain('/oauth/authorize')
        ->and(session()->get('url.intended'))->toContain('client_id=abc');
});
