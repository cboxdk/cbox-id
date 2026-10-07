<?php

declare(strict_types=1);

use App\Platform\Console\HandoffTarget;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Contracts\EnvironmentAdminHandoff;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WHERE A HANDOFF MAY LAND.
 *
 * The context switcher carries the page being read through `/open/{environment}?to=` and
 * the handoff that follows, and anything that rides a redirect and comes back out as a
 * `Location` is an open redirect waiting for one missed case. The allow-list is narrow on
 * purpose ({@see HandoffTarget}); these are the cases it has to refuse, named.
 */
it('accepts an environment console page', function (string $path): void {
    multiTenantDeployment();

    expect(app(HandoffTarget::class)->sanitize($path))->toBe($path);
})->with([
    'a list' => '/admin/users',
    'a create page' => '/admin/roles/new',
    'the home page' => '/admin',
    'an entity page' => '/admin/organizations/01JABCDEFGHJKMNPQRSTVWXYZ0',
]);

it('refuses everything that is not an environment console page', function (mixed $to): void {
    multiTenantDeployment();

    expect(app(HandoffTarget::class)->sanitize($to))->toBeNull();
})->with([
    'protocol-relative' => '//evil.example/admin/users',
    'protocol-relative under admin' => '/admin//evil.example',
    'backslashes' => '\\\\evil.example',
    'a backslash under admin' => '/admin/\\evil.example',
    'an absolute URL' => 'https://evil.example/admin/users',
    'a javascript URL' => 'javascript:alert(1)',
    'a relative path' => 'admin/users',
    'a non-admin path' => '/account',
    'a lookalike prefix' => '/administrator',
    'a dot segment' => '/admin/../account',
    'an encoded slash' => '/admin/%2F%2Fevil.example',
    'a query string' => '/admin/users?next=//evil.example',
    'a fragment' => '/admin/users#x',
    'whitespace' => "/admin/users\n",
    'an unknown page' => '/admin/does-not-exist',
    'the handoff door itself' => '/admin/handoff',
    'a POST-only route' => '/admin/logout',
    'empty' => '',
    'not a string' => [['/admin/users']],
    'null' => null,
    'too long' => '/admin/'.str_repeat('a', 300),
]);

it('binds a target to the token it was minted with', function (): void {
    multiTenantDeployment();
    $targets = app(HandoffTarget::class);

    $signature = $targets->sign('token-one', '/admin/users');

    expect($targets->verify('token-one', '/admin/users', $signature))->toBe('/admin/users')
        // Swapped onto another token — a replayed or a different handoff.
        ->and($targets->verify('token-two', '/admin/users', $signature))->toBeNull()
        // The path changed in transit, signature kept.
        ->and($targets->verify('token-one', '/admin/roles', $signature))->toBeNull()
        ->and($targets->verify('token-one', '/admin/users', ''))->toBeNull()
        ->and($targets->verify('token-one', '/admin/users', null))->toBeNull()
        // A valid MAC does not widen the allow-list.
        ->and($targets->verify('token-one', '//evil.example', $targets->sign('token-one', '//evil.example')))->toBeNull();
});

it('carries a valid target from the workspace host into the handoff, and drops an invalid one', function (): void {
    ['subjectId' => $subjectId, 'project' => $project] = provisionAccount('owner@handoff-target.example');
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $sandbox = app(TenantProvisioner::class)->addEnvironment($project, 'Sandbox', type: EnvironmentType::Sandbox);

    signInAsMember($subjectId);

    $open = fn (string $to): string => (string) $this->get('https://cboxid.com'.route('environment.open', $sandbox->id, absolute: false).'?to='.rawurlencode($to))
        ->assertRedirect()
        ->headers->get('Location');

    $valid = $open('/admin/users');
    parse_str((string) parse_url($valid, PHP_URL_QUERY), $query);

    expect($valid)->toStartWith('https://'.$sandbox->slug.'.cboxid.com/admin/handoff?')
        ->and($query['to'] ?? null)->toBe('/admin/users')
        ->and($query['to_sig'] ?? null)->toBe(app(HandoffTarget::class)->sign((string) $query['token'], '/admin/users'));

    foreach (['//evil.example', '\\\\evil.example', 'https://evil.example', '/account', '/admin/nope'] as $bad) {
        $location = $open($bad);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // Still a handoff — the target is dropped, not the request.
        expect($location)->toStartWith('https://'.$sandbox->slug.'.cboxid.com/admin/handoff?token=')
            ->and($query)->not->toHaveKey('to')
            ->and($query)->not->toHaveKey('to_sig');
    }
});

it('lands a redeemed handoff on its target, and on the home page when the target was tampered with', function (): void {
    ['member' => $member, 'env' => $env, 'envId' => $envId] = envAdminTargetSetup();
    serveOnTestHost($env);

    $handoff = app(EnvironmentAdminHandoff::class);
    $targets = app(HandoffTarget::class);

    // Signed for this token: lands where it was asked to.
    $token = $handoff->mint($member->user_id, $envId);
    $this->get('/admin/handoff?'.http_build_query(['token' => $token, 'to' => '/admin/users', 'to_sig' => $targets->sign($token, '/admin/users')]))
        ->assertRedirect('/admin/users');

    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBe($envId);

    // The path swapped in transit — the session is still established, the target is not.
    $token = $handoff->mint($member->user_id, $envId);
    $this->get('/admin/handoff?'.http_build_query(['token' => $token, 'to' => '/admin/roles', 'to_sig' => $targets->sign($token, '/admin/users')]))
        ->assertRedirect(route('environment.home'));

    // An off-site target, however it is signed, never becomes a Location.
    $token = $handoff->mint($member->user_id, $envId);
    $this->get('/admin/handoff?'.http_build_query(['token' => $token, 'to' => '//evil.example', 'to_sig' => $targets->sign($token, '//evil.example')]))
        ->assertRedirect(route('environment.home'));
});

/** @return array{member: Membership, env: Environment, envId: string} */
function envAdminTargetSetup(): array
{
    $account = provisionAccount('owner@handoff-landing.example');
    multiTenantDeployment();

    return ['member' => $account['member'], 'env' => $account['environment'], 'envId' => $account['environment']->id];
}
