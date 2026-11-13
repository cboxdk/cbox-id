<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;

it('defaults to active identities and combines explicit status with the search', function (): void {
    actAsEnvironmentAdminOfATenant();
    $active = app(Subjects::class)->create('active@panel.test', 'Dana Active');
    $disabled = app(Subjects::class)->create('disabled@panel.test', 'Dana Disabled');
    User::query()->findOrFail($disabled->id)->update(['status' => 'disabled']);

    $page = $this->get(route('environment.users'))->assertOk();
    expect(array_column($page->inertiaProps('users'), 'id'))->toContain($active->id)->not->toContain($disabled->id);
    expect($page->inertiaProps('statusFilter'))->toBe('active');

    $page = $this->get(route('environment.users', ['status' => 'disabled', 'q' => 'Dana']))->assertOk();
    expect(array_column($page->inertiaProps('users'), 'id'))->toBe([$disabled->id]);
    $this->get(route('environment.users', ['status' => 'unknown']))->assertUnprocessable();
});

it('loads a URL-addressed preview without credentials or management history', function (): void {
    actAsEnvironmentAdminOfATenant();
    $user = app(Subjects::class)->create('dana@panel.test', 'Dana');
    $page = $this->get(route('environment.users', ['selected' => $user->id, 'q' => 'dana']))->assertOk();
    $preview = $page->inertiaProps('selectedUser');
    expect($preview['id'])->toBe($user->id)
        ->and($preview['updateHref'])->toBe(route('environment.users.update', $user->id))
        ->and($preview)->not->toHaveKeys(['password', 'sessions', 'impersonation', 'issuedPassword']);
});

it('cannot preview an identity from another environment', function (): void {
    actAsEnvironmentAdminOfATenant();
    $other = Environment::query()->create(['name' => 'Other', 'slug' => 'other-panel', 'status' => 'active', 'is_default' => false]);
    $id = app(EnvironmentContext::class)->runAs(GenericEnvironment::of($other->id),
        fn (): string => app(Subjects::class)->create('other@panel.test', 'Other')->id);
    $this->get(route('environment.users', ['selected' => $id]))->assertNotFound();
});

it('edits only the chosen field and keeps email verification on a name change', function (): void {
    actAsEnvironmentAdminOfATenant();
    $user = app(Subjects::class)->create('dana@panel.test', 'Dana');
    app(Subjects::class)->markEmailVerified($user->id, 'dana@panel.test');

    $this->patch(route('environment.users.update', $user->id), ['name' => 'Dana Updated'])->assertSessionHasNoErrors();
    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->name)->toBe('Dana Updated')
        ->and($fresh->email)->toBe('dana@panel.test')
        ->and($fresh->email_verified_at)->not->toBeNull();

    // A new address is a new recovery channel: the name change above asked nothing, this asks
    // for the step-up first.
    $this->patch(route('environment.users.update', $user->id), ['email' => 'new@panel.test'])->assertRedirect(route('environment.sudo'));
    confirmEnvironmentStepUp();
    $this->patch(route('environment.users.update', $user->id), ['email' => 'new@panel.test'])->assertSessionHasNoErrors();
    $fresh->refresh();
    expect($fresh->name)->toBe('Dana Updated')
        ->and($fresh->email)->toBe('new@panel.test')
        ->and($fresh->email_verified_at)->toBeNull();

    $this->patch(route('environment.users.update', $user->id), ['email' => ''])->assertSessionHasErrors('email');
    $this->patch(route('environment.users.update', $user->id), [])->assertSessionHasErrors('name');
});
