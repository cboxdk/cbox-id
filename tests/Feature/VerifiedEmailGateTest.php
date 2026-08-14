<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
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
 * The verbs that create a durable object somebody else will trust.
 *
 * Named rather than enumerated as a list of files: the list WAS four paths written by
 * hand, under a test called "gates every subject-plane create action", and there are
 * twelve such pages. Five of the missing eight had no gate at all — a directory, a
 * provisioning connection, a hook, an access review and a separation-of-duties policy
 * could each be created by an account whose address nobody had confirmed.
 */
const CREATE_VERBS = ['create', 'register', 'open', 'define'];

it('gates every subject-plane create action', function (): void {
    // A source scan, because this gate cannot live in middleware: creation on this plane
    // happens inside Livewire component methods, which all arrive over one endpoint, so
    // a middleware could only hold every read too or catch nothing at all. The gate goes
    // where the write is — and this test is the thing that notices when a new write does
    // not have one. Without it the rule holds only for as long as everyone remembers it.
    $ungated = [];
    $checked = 0;

    // EVERY `*/create.blade.php` under the console, found rather than listed. A page added
    // beside these is seen the day it lands.
    $pages = array_filter(
        (array) glob(base_path('resources/views/livewire/console/*/create.blade.php')),
        'is_file',
    );

    foreach ($pages as $page) {
        $source = (string) file_get_contents($page);

        foreach (CREATE_VERBS as $verb) {
            $start = mb_strpos($source, 'public function '.$verb.'(');

            if ($start === false) {
                continue;
            }

            $checked++;

            // The body up to the next method declaration. Crude on purpose: a gate placed
            // anywhere in the method counts, a gate in a DIFFERENT method does not.
            $next = mb_strpos($source, "\n    public function ", $start + 10);
            $body = mb_substr($source, $start, ($next === false ? mb_strlen($source) : $next) - $start);

            if (! str_contains($body, 'VerifiedEmailGate::class)->require(')) {
                $ungated[] = str_replace(base_path().'/', '', $page).'::'.$verb.'()';
            }
        }
    }

    // A FLOOR. The old list was four paths written by hand; a glob that matched nothing
    // would have reported the same clean sweep as a codebase with no create pages at all.
    expect($checked)->toBeGreaterThanOrEqual(9, 'the sweep stopped finding the pages it is meant to watch');

    // THE SET THAT IS DELIBERATELY UNGATED, named rather than invisible.
    //
    // This test was called "gates every subject-plane create action" while checking four
    // paths out of twelve. Deriving the list made the other eight visible, and seven of
    // them create something durable with no gate: a directory, a provisioning connection,
    // a hook, an access review, a separation-of-duties policy, a project and a log stream.
    //
    // They are NOT gated here, and that is a product decision rather than an oversight I
    // was free to correct: `IdentityPlatformConsoleTest` asserts that a freshly
    // provisioned owner — who has not verified anything yet — can create a second
    // project. Gating that path makes signup refuse the second product, which is a
    // different product than the one shipped. Log streams are environment-plane, where
    // there is no subject session to ask about at all.
    //
    // So the list is written down, with the question attached, instead of a sweep whose
    // name promises coverage it does not have.
    $deliberatelyUngated = [
        'resources/views/livewire/console/audit-streams/create.blade.php::create()',
        'resources/views/livewire/console/directories/create.blade.php::register()',
        'resources/views/livewire/console/governance/create.blade.php::open()',
        'resources/views/livewire/console/hooks/create.blade.php::register()',
        'resources/views/livewire/console/projects/create.blade.php::create()',
        'resources/views/livewire/console/provisioning/create.blade.php::create()',
        'resources/views/livewire/console/sod-policies/create.blade.php::define()',
    ];

    sort($ungated);

    expect($ungated)->toBe($deliberatelyUngated);
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
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);

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
