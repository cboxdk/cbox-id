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
use Illuminate\Support\Facades\Route;

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
it('gates every subject-plane create action', function (): void {
    /*
     * A SOURCE SCAN, because this gate cannot live in middleware: it is a rule about
     * WRITES, and every console page mixes reads and writes behind the same authorization.
     * The gate goes where the write is — and this test is the thing that notices when a
     * new write does not have one. Without it the rule holds only while everyone
     * remembers it.
     *
     * DERIVED FROM THE ROUTER, not from a directory. It used to walk
     * the Volt console's create pages as well, and while the port ran
     * that half emptied one page at a time — a sweep looking only there would have reported
     * the same clean result for a codebase that had lost every gate as for one that kept
     * them. There is nothing left in that directory, and nothing left to lose quietly.
     */
    $ungated = [];
    $checked = 0;

    /*
     * A console route named `*.create` is a "new X" page, and the `store` on its controller
     * is the write that page submits to. That is exactly the set the old glob held, so the
     * rule and its verdicts survived the move.
     */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_ends_with($name, '.create') || $route->getActionName() === 'Closure') {
            continue;
        }

        [$class] = explode('@', $route->getActionName());

        if (! class_exists($class) || ! method_exists($class, 'store')) {
            continue;
        }

        $method = new ReflectionMethod($class, 'store');
        $file = (string) $method->getFileName();
        $source = (string) file_get_contents($file);
        $body = implode("\n", array_slice(
            explode("\n", $source),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $label = str_replace(base_path().'/', '', $file).'::store()';

        if (in_array($label, $ungated, true)) {
            continue;
        }

        $checked++;

        if (! str_contains($body, 'VerifiedEmailGate::class)->require(')) {
            $ungated[] = $label;
        }
    }

    // A FLOOR. The old list was four paths written by hand; a glob that matched nothing
    // would have reported the same clean sweep as a codebase with no create pages at all.
    expect($checked)->toBeGreaterThanOrEqual(9, 'the sweep stopped finding the pages it is meant to watch');

    // THE RULE, NOT A LIST: gate what confers REACH OR TRUST OUTSIDE THE TENANT.
    //
    // GATED, because each one hands an unverified account something that acts beyond the
    // tenant: an OAuth client and an SSO connection are credentials other systems trust, a
    // role grants access, and a webhook, a directory, a provisioning connection and a hook
    // all make this platform send requests to a URL the creator chose or pull identities
    // from one. That is the shape worth holding until an address is confirmed.
    //
    // NOT GATED, and each for its own reason rather than by omission:
    //
    //  - `projects` — a project reaches nothing, and refusing one mid-signup is a growth
    //    regression. `IdentityPlatformConsoleTest` asserts a freshly provisioned owner can
    //    create a second product, and that test is right.
    //  - `governance` and `sod-policies` — internal bookkeeping. An access review and a
    //    separation-of-duties policy are read by this tenant's own administrators and
    //    nobody else, so gating them buys nothing and costs onboarding friction.
    //  - `audit-streams` — environment plane, where there is no subject session to ask
    //    about at all.
    //  - `environment.organizations` — the environment plane's tenants. A tenant record
    //    reaches nothing on its own: it is this customer's own bookkeeping of who their
    //    customers are, the same shape as `projects` above, and the writes that DO reach
    //    outward from it (inviting somebody by email, claiming a domain) are their own
    //    endpoints rather than this one.
    //
    // `environment.users` is NOT on this list, and it is the reason to state the rule as a
    // rule: it looked like bookkeeping too, and it is the widest reach on the console —
    // creating a user sends a live sign-in link to an address the creator named.
    //
    // Each entry is one page. `projects` appeared twice while its blade was still on disk
    // beside the controller that replaced it; the blade is deleted now, and the duplicate
    // went with it exactly as this list said it would.
    $deliberatelyUngated = [
        'app/Http/Controllers/Console/AccessReviewController.php::store()',
        'app/Http/Controllers/Console/EnvironmentOrganizationController.php::store()',
        'app/Http/Controllers/Console/LogStreamController.php::store()',
        'app/Http/Controllers/Console/ProjectController.php::store()',
        'app/Http/Controllers/Console/RoleConflictController.php::store()',
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
    test()->from(route('webhooks.create'))
        ->post(route('webhooks.store'), [
            'url' => 'https://example.test/hook',
            'eventTypes' => ['user.created'],
        ])
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
    test()->from(route('webhooks.create'))
        ->post(route('webhooks.store'), [
            'url' => 'https://example.test/hook',
            'eventTypes' => ['user.created'],
        ])
        ->assertSessionHasNoErrors();

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
    // UNVERIFIED ON PURPOSE — this is the test that owns the rule, so it states the state
    // rather than inheriting the helper's default. (Established admins are verified there,
    // so that unrelated tests do not exercise this rule by accident.)
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id, emailVerified: false);

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
