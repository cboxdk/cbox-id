<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Models\Account;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Livewire\Volt\Volt;

// Guarded so they coexist with the same helpers in the other workspace test files.
if (! function_exists('provisionAccount')) {
    /**
     * @return array{member: AccountMember, account: Account, project: Project, environment: Environment}
     */
    function provisionAccount(string $email = 'owner@acme.example'): array
    {
        $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: $email,
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        return ['member' => $result->member, 'account' => $result->account, 'project' => $result->project, 'environment' => $result->environment];
    }
}

if (! function_exists('memberWithRole')) {
    function memberWithRole(string $accountId, AccountRole $role, string $email): AccountMember
    {
        $members = app(AccountMembers::class);
        $m = $members->invite($accountId, $email, $role);
        $members->activate($m->id, 'a-strong-unbreached-passphrase');

        return $members->find($m->id);
    }
}

beforeEach(function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    // Swap in an in-memory DNS resolver, then forget the domain service so it
    // rebuilds against the fake.
    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    app()->forgetInstance(EnvironmentDomains::class);
});

it('walks an admin from requesting a custom domain to a verified issuer', function (): void {
    ['member' => $owner, 'account' => $account, 'environment' => $env] = provisionAccount();
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id]);

    $page = Volt::test('workspace.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->set('newDomain', 'id.acme.com')
        ->call('request');

    // The exact TXT record to publish is now shown.
    $challenge = app(EnvironmentDomains::class)->challenge($env->id);
    expect($challenge)->not->toBeNull()
        ->and($challenge->recordName)->toBe('_cbox-id-challenge.id.acme.com');
    $page->assertSee($challenge->recordName)->assertSee($challenge->recordValue);

    // Not verified until the record is live.
    $page->call('verify');
    expect($env->fresh()->domain)->toBeNull();
    $page->assertSee('visible yet');

    // Publish the record and verify: the domain is promoted + the event is logged.
    $this->dns->publish($challenge->recordName, $challenge->recordValue);
    $page->call('verify');

    expect($env->fresh()->domain)->toBe('id.acme.com')
        ->and(AuditEntry::query()->where('scope', $account->id)
            ->where('action', 'account.custom_domain_verified')->exists())->toBeTrue();
});

it('surfaces a validation error for a platform-reserved domain', function (): void {
    ['member' => $owner, 'environment' => $env] = provisionAccount();
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id]);

    Volt::test('workspace.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->set('newDomain', 'acme.cboxid.com')
        ->call('request')
        ->assertHasErrors('newDomain');

    expect(app(EnvironmentDomains::class)->challenge($env->id))->toBeNull();
});

it('removes a verified domain, falling back to the default issuer', function (): void {
    ['member' => $owner, 'environment' => $env] = provisionAccount();
    $env->update(['domain' => 'id.acme.com']);
    $this->withSession([AccountAuth::SESSION_KEY => $owner->id]);

    Volt::test('workspace.environment-domains')
        ->set('selectedEnvironment', $env->id)
        ->call('remove');

    expect($env->fresh()->domain)->toBeNull();
});

it('refuses the domains page to a member who cannot manage environments', function (): void {
    ['account' => $account] = provisionAccount();
    $viewer = memberWithRole($account->id, AccountRole::Billing, 'billing2@acme.example');

    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])
        ->get(route('workspace.environment-domains'))
        ->assertRedirect(route('workspace.home'));
});
