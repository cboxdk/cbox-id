<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\OrganizationProjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * An operator standing a customer up from the console.
 *
 * `TenantProvisioner::provision()` is the package's own entry point and it had no caller
 * in the operator console at all: an operator could suspend a customer, walk their estate
 * and impersonate their people, and could not create one. Onboarding anybody who had not
 * self-served meant tinker on a production console.
 */
beforeEach(function (): void {
    actAsOperator('provisioning-op@platform.test');

    platformRootEnvironment();

    Mail::fake();
});

it('stands up the whole customer from the operator console', function (): void {
    createCustomer(['ownerEmail' => 'ada@northwind.example', 'environmentLimit' => 5])
        ->assertSessionHasNoErrors();

    app(PlatformRoot::class)->run(function (): void {
        $organization = Organization::query()->where('name', 'Northwind')->first();

        expect($organization)->not->toBeNull();

        // The owner is an ordinary SUBJECT — the same row shape a tenant's own user
        // occupies — holding the authority as a membership beside it.
        $owner = app(Subjects::class)->findByEmail('ada@northwind.example');

        expect($owner)->not->toBeNull()
            ->and(app(Memberships::class)->forUser($owner->id)->first()?->role)
            ->toBe(MembershipRole::Owner);

        // …and the whole chain below them: organization → project → environment. A
        // customer with no product, or a product with no environment, is a half-born
        // customer, which is the failure the provisioner's single transaction exists to
        // make impossible.
        $project = app(OrganizationProjects::class)->forOrganization($organization->id)->first();

        expect($project)->not->toBeNull()
            ->and($project->environment_limit)->toBe(5)
            ->and(Environment::query()->where('project_id', $project->id)->exists())->toBeTrue();
    });
});

/**
 * The whole shape of the create action, and the reason it does not take a password field.
 *
 * An operator who typed a password for somebody else would be an operator who knows a
 * customer's credential — precisely the authority the platform must not have over its
 * customers' identities. The blueprint requires one, so it gets a throwaway that is never
 * rendered, never logged and never returned, and the owner is sent a link to choose their
 * own.
 */
it('emails the owner a link instead of choosing their password', function (): void {
    createCustomer(['ownerEmail' => 'ada@northwind.example'])->assertSessionHasNoErrors();

    Mail::assertSent(PasswordResetMail::class, fn ($mail): bool => $mail->hasTo('ada@northwind.example'));
})->group('security');

it('reuses the identity an address already holds rather than minting a second', function (): void {
    // The operator's OWN address, which is the case that actually bites: whoever runs the
    // deployment is also a customer on it, and `users` is unique on (environment_id,
    // email). Creating unconditionally would fail provisioning outright for them.
    ['subjectId' => $existing] = provisionAccount('shared@cbox.test');

    createCustomer([
        'name' => 'Second Company',
        'ownerName' => 'Same Person',
        'ownerEmail' => 'shared@cbox.test',
    ])->assertSessionHasNoErrors();

    app(PlatformRoot::class)->run(function () use ($existing): void {
        $owner = app(Subjects::class)->findByEmail('shared@cbox.test');

        expect($owner->id)->toBe($existing)
            // One person, two customers — the authority is the membership, which is true
            // per organization, not per person.
            ->and(app(Memberships::class)->forUser($existing)->count())->toBe(2);
    });
});

it('refuses to create a customer for anyone who is not an operator', function (): void {
    ['subjectId' => $memberSubjectId] = provisionAccount('plain@acme.example');

    /*
     * THE WRITE, ASKED DIRECTLY — which under Livewire took a replayed snapshot to reach at
     * all. A Livewire action was its own HTTP entry point into a component rehydrated from
     * a snapshot the caller supplied, so the page's guard had to be re-asked in `boot()`
     * and a test that merely mounted the component as a member proved nothing: the mount
     * 404'd and the harness never produced a snapshot to act on.
     *
     * The create is its own route with its own middleware stack now, so the attack and the
     * test are the same thing: POST it as somebody who is not an operator.
     */
    signInAsMember($memberSubjectId);

    // 404 rather than 403, on the page and on the write alike: the operator console is a
    // staff surface, and a 403 confirms to a stranger that the page exists.
    $this->get(route('platform.customers'))->assertNotFound();

    createCustomer(['name' => 'Should Not Exist', 'ownerEmail' => 'nobody@example.test'])
        ->assertNotFound();

    app(PlatformRoot::class)->run(function (): void {
        expect(Organization::query()->where('name', 'Should Not Exist')->exists())->toBeFalse();
    });

    Mail::assertNothingSent();
})->group('security');

it('refuses a blank form rather than provisioning an unnamed customer', function (): void {
    createCustomer(['name' => '', 'ownerEmail' => '', 'ownerName' => ''])
        ->assertSessionHasErrors(['name', 'ownerEmail', 'ownerName']);

    Mail::assertNothingSent();
});
