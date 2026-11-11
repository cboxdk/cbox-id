<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

/*
| Plan step 7: the console's URLs carry public ids, a bookmark with a bare id still opens,
| a public id of the wrong kind is a 404, and a protocol URL registered with somebody else's
| identity provider keeps its shape.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner-ids@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $this->organizationId = $result->organization->id;
    $this->userId = app(Subjects::class)->create('lin@acme.example', 'Lin', 'the-original-passphrase')->id;
});

it('builds console URLs with public ids, wherever a URL is built', function (): void {
    expect(route('environment.users.show', $this->userId))->toEndWith('/users/usr_'.$this->userId)
        ->and(route('environment.users.show', ['user' => $this->userId]))->toEndWith('/users/usr_'.$this->userId)
        ->and(route('environment.organizations.show', $this->organizationId))->toEndWith('/organizations/org_'.$this->organizationId);
});

it('opens a console page by its public id and by the bare id a bookmark still holds', function (): void {
    $this->get(route('environment.users.show', $this->userId))->assertOk();
    $this->get(envConsole('users/'.$this->userId))->assertOk();
    $this->get(envConsole('users/usr_'.$this->userId))->assertOk();
});

it('answers a console URL holding another kind\'s public id with 404', function (): void {
    $this->get(envConsole('users/org_'.$this->userId))->assertNotFound();
})->group('security');

it('keeps the shape of every URL registered outside Cbox ID', function (): void {
    $connection = '01jcz8x7t4m2k9q3r5v6w8y0ab';

    expect(route('sso.oidc.callback', $connection))->toEndWith('/sso/oidc/'.$connection.'/callback')
        ->and(route('sso.saml.acs', $connection))->toEndWith('/sso/saml/'.$connection.'/acs');
});

it('lets an administrator move a webhook endpoint to public ids, and back', function (): void {
    $endpoint = app(WebhookRegistry::class)->registerForEnvironment('https://hooks.acme.example/in', ['user.created'])->endpoint;

    $this->get(route('environment.webhooks.show', $endpoint->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('endpoint.idFormat', 'raw')
            ->where('urls.idFormat', fn (string $url): bool => str_contains($url, '/webhooks/whe_'.$endpoint->id.'/id-format')));

    $this->post(route('environment.webhooks.id-format', $endpoint->id), ['idFormat' => 'prefixed'])->assertRedirect();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('id_format'))->toBe('prefixed');

    $this->post(route('environment.webhooks.id-format', $endpoint->id), ['idFormat' => 'raw'])->assertRedirect();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('id_format'))->toBe('raw');

    $this->post(route('environment.webhooks.id-format', $endpoint->id), ['idFormat' => 'something-else'])->assertSessionHasErrors();
    expect(WebhookEndpoint::query()->whereKey($endpoint->id)->value('id_format'))->toBe('raw');
});
