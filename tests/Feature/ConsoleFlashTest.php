<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Cbox\Id\Identity\Contracts\Subjects;

it('delivers an action confirmation through native flash for one response only', function (): void {
    actAsEnvironmentAdminOfATenant();
    confirmEnvironmentStepUp();
    $user = app(Subjects::class)->create('flash@panel.test', 'Before');
    $url = route('environment.users', ['selected' => $user->id]);
    $this->from($url)->patch(route('environment.users.update', $user->id), ['name' => 'After'])
        ->assertRedirect($url);
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ];
    $this->get($url, $headers)
        ->assertOk()
        ->assertJsonPath('flash.status', 'Profile updated.')
        ->assertJsonPath('props.flash.status', 'Profile updated.');

    $this->get($url, $headers)
        ->assertOk()
        ->assertJsonMissingPath('flash');
});
