<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

it('refuses to switch into an organization the user does not belong to', function () {
    [$subject, $orgA] = accountWithOrg('u@acme.test');
    $orgB = app(Organizations::class)->create(new NewOrganization('Rival Corp', 'rival-'.uniqid()));
    $sessionId = app(SessionManager::class)->start($subject->id, $orgA->id, ['pwd'])->id;

    $this->withSession([PlatformAuth::SESSION_KEY => $sessionId, PlatformAuth::ORG_KEY => $orgA->id])
        ->post('/organization/switch', ['organization' => $orgB->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(PlatformAuth::ORG_KEY, $orgA->id); // unchanged — B was rejected
});

it('ignores a tampered org id in the cookie and falls back to a real membership', function () {
    [$subject, $orgA] = accountWithOrg('v@acme.test');
    $orgB = app(Organizations::class)->create(new NewOrganization('Rival Corp', 'rival-'.uniqid()));
    $sessionId = app(SessionManager::class)->start($subject->id, $orgA->id, ['pwd'])->id;

    // Attacker forges ORG_KEY to an org they are not a member of.
    $this->withSession([PlatformAuth::SESSION_KEY => $sessionId, PlatformAuth::ORG_KEY => $orgB->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertSessionHas(PlatformAuth::ORG_KEY, $orgA->id) // corrected back to a real membership
        ->assertSee($orgA->name)
        ->assertDontSee('Rival Corp');
});
