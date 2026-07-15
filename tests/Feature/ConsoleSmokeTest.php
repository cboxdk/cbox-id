<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function signInAdminHttp(): void
{
    $subject = app(Subjects::class)->create('smoke@acme.test', 'Smoke Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-smoke'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);

    // The Authenticate middleware reads this session key and rebuilds CurrentUser.
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');
}

it('renders every new console page end-to-end for an admin', function (string $route): void {
    signInAdminHttp();

    $this->get(route($route))->assertOk();
})->with(['governance', 'sod-policies', 'provisioning', 'hooks', 'audit-streams', 'approvals']);
