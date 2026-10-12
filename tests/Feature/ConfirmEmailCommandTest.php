<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * When the deployment cannot send mail, whoever runs commands in it can vouch for an
 * address — the owner of cboxid.com could neither receive a link nor change anything.
 */
it('confirms an existing platform-root person\'s address, once, audited', function (): void {
    ['subjectId' => $subjectId] = provisionAccount('owner@acme.example');

    $this->artisan('cbox-id:users:confirm-email', ['email' => 'Owner@Acme.example'])->assertSuccessful();

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->find($subjectId)?->emailVerified))->toBeTrue();

    $this->artisan('cbox-id:users:confirm-email', ['email' => 'owner@acme.example'])
        ->expectsOutputToContain('already confirmed')
        ->assertSuccessful();
});

it('refuses an address nobody signs in with', function (): void {
    provisionAccount('owner@acme.example');

    $this->artisan('cbox-id:users:confirm-email', ['email' => 'nobody@acme.example'])->assertFailed();
});
