<?php

declare(strict_types=1);

use Cbox\Id\FeatureFlags\Models\FeatureFlag;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/*
|--------------------------------------------------------------------------
| Developers › Feature flags
|--------------------------------------------------------------------------
| The environment console's page for the switches its apps ask about: define one, say who
| it is on for (a user by email, an organization from the picker, a rollout), check the
| answer for somebody, delete it. Every write runs the same action the API does.
*/

beforeEach(function (): void {
    installedDeployment();
});

function consoleFlag(): FeatureFlag
{
    test()->from(route('environment.feature-flags.create'))
        ->post(route('environment.feature-flags.store'), ['key' => 'new-dashboard', 'description' => 'The redesign', 'defaultValue' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    return FeatureFlag::query()->where('key', 'new-dashboard')->sole();
}

it('lists, creates and shows a flag', function (): void {
    crudSetup();

    $this->get(route('environment.feature-flags'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('console/feature-flags/index')
        ->where('flags', []));

    $flag = consoleFlag();

    $this->get(route('environment.feature-flags'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('flags.0.key', 'new-dashboard')
        ->where('flags.0.enabled', true)
        ->where('flags.0.defaultValue', false));

    $this->get(route('environment.feature-flags.show', $flag->id))->assertOk()->assertInertia(fn ($page) => $page
        ->component('console/feature-flags/show')
        ->where('flag.key', 'new-dashboard')
        ->where('flag.description', 'The redesign')
        ->where('evaluation', null));
});

it('puts a bad key back on the form', function (): void {
    crudSetup();

    $this->from(route('environment.feature-flags.create'))
        ->post(route('environment.feature-flags.store'), ['key' => 'Has Spaces', 'description' => '', 'defaultValue' => false])
        ->assertRedirect(route('environment.feature-flags.create'))
        ->assertSessionHasErrors('key');
});

it('targets a user by email, an organization and a rollout, and answers who it is on for', function (): void {
    crudSetup();
    $flag = consoleFlag();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme Beta', 'acme-beta-flags'));
    $ada = app(Subjects::class)->create('ada@flags.example', 'Ada');

    $this->from(route('environment.feature-flags.show', $flag->id))
        ->patch(route('environment.feature-flags.update', $flag->id), [
            'organizations' => [['id' => $acme->id, 'label' => 'Acme Beta', 'enabled' => true]],
            'users' => [['id' => 'ada@flags.example', 'label' => 'ada@flags.example', 'enabled' => false]],
            'rolloutPercentage' => '20',
        ])
        ->assertSessionHasNoErrors();

    $flag = FeatureFlag::query()->with('targets')->findOrFail($flag->id);

    expect($flag->targeting()->users)->toBe([$ada->id => false])
        ->and($flag->targeting()->organizations)->toBe([$acme->id => true])
        ->and($flag->rollout_percentage)->toBe(20)
        ->and($flag->description)->toBe('The redesign');

    $this->get(route('environment.feature-flags.show', $flag->id))->assertInertia(fn ($page) => $page
        ->where('users.0.label', 'ada@flags.example')
        ->where('organizations.0.label', 'Acme Beta'));

    // Ada's own rule (off) beats her organization's (on).
    $this->get(route('environment.feature-flags.show', ['flag' => $flag->id, 'user' => 'ada@flags.example', 'organization' => $acme->id]))
        ->assertInertia(fn ($page) => $page
            ->where('evaluation.enabled', false)
            ->where('evaluation.reason', 'user_target'));

    $this->get(route('environment.feature-flags.show', ['flag' => $flag->id, 'organization' => $acme->id]))
        ->assertInertia(fn ($page) => $page
            ->where('evaluation.enabled', true)
            ->where('evaluation.reason', 'organization_target'));

    // An email nobody signs in with is put back on the form.
    $this->from(route('environment.feature-flags.show', $flag->id))
        ->patch(route('environment.feature-flags.update', $flag->id), ['users' => [['id' => 'ghost@flags.example', 'enabled' => true]]])
        ->assertSessionHasErrors('users');
});

it('switches a flag off without touching its rules, and deletes it', function (): void {
    crudSetup();
    $flag = consoleFlag();
    $acme = app(Organizations::class)->create(new NewOrganization('Acme Beta', 'acme-beta-off'));

    $this->patch(route('environment.feature-flags.update', $flag->id), ['organizations' => [['id' => $acme->id, 'enabled' => true]]])
        ->assertSessionHasNoErrors();

    $this->patch(route('environment.feature-flags.update', $flag->id), ['description' => 'Still the redesign', 'enabled' => false, 'defaultValue' => false])
        ->assertSessionHasNoErrors();

    $flag = FeatureFlag::query()->with('targets')->findOrFail($flag->id);

    expect($flag->enabled)->toBeFalse()
        ->and($flag->description)->toBe('Still the redesign')
        ->and($flag->targeting()->organizations)->toBe([$acme->id => true]);

    $this->delete(route('environment.feature-flags.destroy', $flag->id))
        ->assertRedirect(route('environment.feature-flags'));

    expect(FeatureFlag::query()->count())->toBe(0);
    $this->get(route('environment.feature-flags.show', $flag->id))->assertNotFound();
});
