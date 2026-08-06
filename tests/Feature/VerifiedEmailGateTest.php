<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;

/**
 * A social sign-in creates the account immediately, but the address on it is only a
 * claim the provider passed along. Reading is free; creating durable objects other
 * people will trust is held until we have confirmed the address ourselves.
 */

/**
 * The subject-plane pages where an unverified account could otherwise create something.
 * A capability merged onto one component for both planes lives under `livewire/console`
 * and still serves this plane, so the sweep follows the component rather than losing the
 * page the moment it is unified.
 */
const GATED_CREATE_PAGES = [
    'resources/views/livewire/console/clients/create.blade.php',
    'resources/views/livewire/console/connections/create.blade.php',
    'resources/views/livewire/console/roles/create.blade.php',
    // Webhooks serves BOTH console planes from one component, so its create() lives
    // under console/ rather than at livewire/<route>. The gate itself is unchanged —
    // it is asked on the organization plane, where a subject session exists to ask
    // about — and the sweep follows the page rather than losing it to the merge.
    'resources/views/livewire/console/webhooks/create.blade.php',
];

it('gates every subject-plane create action', function (): void {
    // A source scan, because this gate cannot live in middleware: creation on this plane
    // happens inside Livewire component methods, which all arrive over one endpoint, so
    // a middleware could only hold every read too or catch nothing at all. The gate goes
    // where the write is — and this test is the thing that notices when a new write does
    // not have one. Without it the rule holds only for as long as everyone remembers it.
    $ungated = [];

    foreach (GATED_CREATE_PAGES as $page) {
        $source = (string) file_get_contents(base_path($page));
        $start = mb_strpos($source, 'public function create(');

        if ($start === false) {
            $ungated[] = $page.' (no create() found — was it renamed?)';

            continue;
        }

        // The body up to the next method declaration. Crude on purpose: a gate placed
        // anywhere in the method counts, a gate in a DIFFERENT method does not.
        $next = mb_strpos($source, "\n    public function ", $start + 10);
        $body = mb_substr($source, $start, ($next === false ? mb_strlen($source) : $next) - $start);

        if (! str_contains($body, 'VerifiedEmailGate::class)->require(')) {
            $ungated[] = $page;
        }
    }

    expect($ungated)->toBe([]);
})->group('security');

it('refuses a create from an account whose address we have not confirmed', function (): void {
    actingAsRole(MembershipRole::Owner, emailVerified: false);

    // Exactly the state a social signup lands in: real account, usable session, address
    // asserted by a provider and confirmed by nobody.
    $me = app(CurrentUser::class);
    expect($me->emailVerified())->toBeFalse();

    confirmConsoleStepUp();
    Volt::test('console.webhooks.create')
        ->set('url', 'https://example.test/hook')
        ->set('eventTypes', ['user.created'])
        ->call('create')
        ->assertForbidden();

    // The refusal has to be the absence of a webhook, not just an unhappy response.
    expect(app(WebhookRegistry::class)->matching($me->organizationId(), 'user.created'))->toBeEmpty();
})->group('security');

it('allows the same create once the address is confirmed', function (): void {
    // The other half of the falsification: if this passed while the account was still
    // unverified, the test above would be proving nothing about verification.
    actingAsRole(MembershipRole::Owner, emailVerified: false);
    $me = app(CurrentUser::class);

    $subject = app(Subjects::class)->find($me->id());
    app(Subjects::class)->markEmailVerified($me->id(), (string) $subject?->email);
    $me->refreshSubject(app(Subjects::class)->find($me->id()));

    confirmConsoleStepUp();
    Volt::test('console.webhooks.create')
        ->set('url', 'https://example.test/hook')
        ->set('eventTypes', ['user.created'])
        ->call('create')
        ->assertHasNoErrors();

    // Asserting the OBJECT, not the absence of errors: a create that silently did
    // nothing would satisfy assertHasNoErrors and leave the test above proving that
    // creation is broken rather than that verification gates it.
    expect(app(WebhookRegistry::class)->matching($me->organizationId(), 'user.created'))->not->toBeEmpty();
})->group('security');

it('says what to do rather than only refusing', function (): void {
    // A bare "forbidden" reads as a product bug to the one person who can clear it in
    // seconds by opening their inbox.
    $gate = app(VerifiedEmailGate::class);

    expect(fn () => $gate->require('create an application'))
        ->toThrow(AuthorizationException::class, 'Confirm your email address before you create an application.');
})->group('security');

it('holds an unverified environment administrator back too', function (): void {
    // The gate read CurrentUser directly — the organization plane's answer, empty on the
    // other one — so it reported every operator as unverified and refused every creation
    // they attempted. Four merged capabilities each worked around that by calling the
    // gate on one plane only, which is four private answers to one question.
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(
        new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'gate-owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        )
    );

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);

    $scope = app(ConsoleScope::class);
    $actorId = $scope->actorId();

    // A freshly provisioned owner has not confirmed their address, so the gate holds.
    expect($scope->actorEmailVerified())->toBeFalse()
        ->and(app(VerifiedEmailGate::class)->verified())->toBeFalse();

    // Confirm it in the PLATFORM ROOT, where account members actually live — the
    // environment scope is deny-by-default, so a lookup in the wrong scope would find
    // nothing and report a verified operator as unverified.
    app(PlatformRoot::class)->run(function () use ($actorId): void {
        $subject = app(Subjects::class)->find($actorId);
        app(Subjects::class)->markEmailVerified($actorId, (string) $subject?->email);
    });

    expect(app(ConsoleScope::class)->actorEmailVerified())->toBeTrue();
})->group('security');
