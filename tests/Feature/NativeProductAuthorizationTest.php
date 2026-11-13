<?php

use App\Actions\OrgPlane\Environments\ListEnvironments;
use App\Actions\OrgPlane\GetOrganization;
use App\Actions\OrgPlane\Projects\ListProjects;
use App\Mail\InvitationMail;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\ValueObjects\Inviter;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\NativeProduct\NativeProductAccess;
use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductMembers;
use App\Platform\NativeProduct\NativeTeamInvitations;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('rechecks native ownership and catalog validity on the next HTTP request', function (string $revocation): void {
    $account = provisionAccount();
    $organizationId = $account['organization']->id;
    $subject = $account['subjectId'];
    $client = app(NativeProductClient::class)->clientId();
    Route::get('/__native-access/{organization}', function (string $organization) use ($subject) {
        $access = app(NativeProductAccess::class);

        return response()->json([
            'first' => $access->forWebSubject($subject, $organization)->canManageMembers(),
            'second' => $access->forWebSubject($subject, $organization)->canManageMembers(),
        ]);
    });
    $this->getJson('/__native-access/'.$organizationId)->assertOk()->assertJson(['first' => true, 'second' => true]);
    $this->getJson('/__native-access/unrelated-organization')->assertOk()->assertJson(['first' => false, 'second' => false]);

    match ($revocation) {
        'organization' => DB::table('organizations')->where('id', $organizationId)->update(['status' => 'suspended']),
        'membership' => DB::table('memberships')->where('organization_id', $organizationId)->where('user_id', $subject)->update(['status' => 'suspended']),
        'subject' => DB::table('users')->where('id', $subject)->update(['status' => 'disabled']),
        'locked subject' => DB::table('users')->where('id', $subject)->update(['status' => 'locked']),
        'ownership' => DB::table('memberships')->where('organization_id', $organizationId)->where('user_id', $subject)->update(['role' => 'member']),
        'client' => DB::table('oauth_clients')->where('client_id', $client)->delete(),
        'registration' => DB::table('native_product_clients')->where('product', 'id')->update(['environment_id' => $account['environment']->id]),
        'root environment' => DB::table('environments')->where('is_default', true)->update(['status' => 'suspended']),
    };

    nextRequest();
    $response = $this->getJson('/__native-access/'.$organizationId);
    if (in_array($revocation, ['client', 'registration', 'root environment'], true)) {
        $response->assertServiceUnavailable();
    } else {
        $response->assertOk()->assertJson(['first' => false, 'second' => false]);
    }
})->with(['organization', 'membership', 'subject', 'locked subject', 'ownership', 'client', 'registration', 'root environment']);

it('publishes stable native definitions without granting shared administrators product access', function () {
    $account = provisionAccount();
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Admin, 'native-admin@example.test');
    $client = app(NativeProductClient::class)->clientId();
    syncNativeProductCatalog();
    expect(app(NativeProductClient::class)->clientId())->toBe($client);
    expect(app(NativeProductAccess::class)->forSubject($subject, $account['organization']->id)->any())->toBeFalse();
    grantNativeProductRoles($member, ['viewer', 'billing']);
    $access = app(NativeProductAccess::class)->forSubject($subject, $account['organization']->id);
    expect($access->canReadEnvironments())->toBeTrue()->and($access->canManageEnvironments())->toBeFalse()
        ->and($access->canReadBilling())->toBeTrue()->and($access->canManageMembers())->toBeFalse();
    $service = app(NativeProductMembers::class);
    $version = $service->access($member)['roles_version'];
    $service->replace($member, [], $version, AuditActor::system());
    expect(app(NativeProductAccess::class)->forSubject($subject, $account['organization']->id)->any())->toBeFalse();
    expect(fn () => $service->replace($member, ['admin'], $version, AuditActor::system()))->toThrow(ActionRefused::class);
});

it('invites multiple native roles without changing an existing shared membership', function () {
    Mail::fake();
    $account = provisionAccount();
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Admin, 'native-invite@example.test');
    $service = app(NativeTeamInvitations::class);
    $inviter = new Inviter($account['subjectId'], 'Owner');
    $actor = AuditActor::user($account['subjectId']);
    $invitation = $service->send($account['organization']->id, 'native-invite@example.test', ['viewer', 'billing'], $inviter, $actor);
    expect($service->countPending($account['organization']->id))->toBe(1)
        ->and($service->pending($account['organization']->id)[0]->id)->toBe($invitation->id);
    $mail = Mail::queued(InvitationMail::class)->last();
    preg_match('#/invitations/([^/]+)/accept#', $mail->url, $matches);
    $accepted = app(PlatformRoot::class)->run(fn () => app(OrganizationInvitations::class)->accept($matches[1]));
    expect($accepted->membership->role)->toBe(MembershipRole::Admin)
        ->and(app(NativeProductMembers::class)->access($member)['roles'])->toEqualCanonicalizing(['viewer', 'billing'])
        ->and($service->countPending($account['organization']->id))->toBe(0);
});

it('serves native roles and replaces them through the member page without removing shared membership', function () {
    $account = provisionAccount();
    [$member] = addMember($account['organization']->id, MembershipRole::Member, 'native-row@example.test');
    grantNativeProductRoles($member, ['viewer']);
    signInAsMember($account['subjectId']);
    confirmStepUp();
    $this->get(route('orgs.settings.members'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('members', 2));
    $roles = app(NativeProductMembers::class);
    $version = $roles->access($member)['roles_version'];
    $this->from(route('orgs.settings.members'))->patch(route('orgs.settings.members.role', ['member' => $member->id, 'organization' => $account['organization']->id]), ['roles' => ['viewer', 'billing'], 'roles_version' => $version])->assertSessionHasNoErrors();
    expect($roles->access($member)['roles'])->toEqualCanonicalizing(['viewer', 'billing']);
    $this->from(route('orgs.settings.members'))->delete(route('orgs.settings.members.remove', ['member' => $member->id, 'organization' => $account['organization']->id]), ['roles_version' => $roles->access($member)['roles_version']])->assertSessionHasNoErrors();
    expect($roles->access($member)['roles'])->toBe([]);
    $liveMember = app(PlatformRoot::class)->run(fn () => app(Memberships::class)->forOrganization($account['organization']->id)->firstWhere('id', $member->id));
    expect($liveMember)->not->toBeNull();
});

it('fences native invitations by organization and excludes revoked invitations', function () {
    Mail::fake();
    $first = provisionAccount();
    $second = provisionAccount('native-second-owner@example.test');
    $service = app(NativeTeamInvitations::class);
    $inviter = new Inviter($first['subjectId'], 'Owner');
    $actor = AuditActor::user($first['subjectId']);
    $invitation = $service->send($first['organization']->id, 'native-pending@example.test', ['viewer'], $inviter, $actor);
    expect($service->countPending($second['organization']->id))->toBe(0);
    expect(fn () => $service->revoke($second['organization']->id, $invitation->id, $actor))->toThrow(ActionRefused::class);
    $service->revoke($first['organization']->id, $invitation->id, $actor);
    expect($service->countPending($first['organization']->id))->toBe(0);
    expect(fn () => $service->resend($first['organization']->id, $invitation->id, $inviter, $actor))->toThrow(ActionRefused::class);
});

it('uses independent optimistic versions for environment access and denies an empty selected scope', function () {
    $account = provisionAccount();
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Admin, 'native-scoped@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $version = $rows->version($member);
    $rows->replace($member, false, [], $version);
    expect($rows->ids($account['organization']->id, $subject))->toBe([]);
    expect(fn () => $rows->replace($member, true, [], $version))->toThrow(ActionRefused::class);
    signInAsMember($account['subjectId']);
    confirmStepUp();
    $this->putJson(route('orgs.settings.members.access', ['member' => $member->id, 'organization' => $account['organization']->id]), ['all' => true, 'environmentIds' => [], 'environment_access_version' => $version])->assertStatus(409);
    expect($rows->ids($account['organization']->id, $subject))->toBe([]);
});

it('lets a Billing-only native member read organization identity but no operational lists', function () {
    $account = provisionAccount();
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Member, 'native-billing-only@example.test');
    grantNativeProductRoles($member, ['billing']);
    signInAsMember($subject);
    $principal = new ConsoleSessionPrincipal(app(ConsoleScope::class));
    $runner = app(ActionRunner::class);
    $result = $runner->run(GetOrganization::class, $principal, []);
    expect($result->payload)->toMatchArray(['id' => $account['organization']->id, 'name' => 'Acme'])->not->toHaveKey('projects');
    expect(fn () => $runner->run(ListProjects::class, $principal, []))->toThrow(AuthorizationException::class);
    expect(fn () => $runner->run(ListEnvironments::class, $principal, []))->toThrow(AuthorizationException::class);
});

it('filters operational lists and counts to a native Reader’s selected environments', function () {
    $account = provisionAccount();
    $other = app(TenantProvisioner::class)->addEnvironment($account['project'], 'Staging');
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Member, 'native-selected-reader@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, false, [$other->id], $rows->version($member));
    signInAsMember($subject);
    $principal = new ConsoleSessionPrincipal(app(ConsoleScope::class));
    $runner = app(ActionRunner::class);
    $projects = $runner->run(ListProjects::class, $principal, []);
    expect($projects->payload[0]['environments_used'])->toBe(1);
    $environments = $runner->run(ListEnvironments::class, $principal, []);
    expect(array_column($environments->payload, 'id'))->toBe([$other->id]);
});

it('renders environment Reader controls read-only while retaining readable records', function () {
    $owner = crudSetup();
    $user = app(Subjects::class)->create('readable@example.test', 'Readable');
    [$reader, $subject] = addMember($owner['member']->organization_id, MembershipRole::Member, 'native-ui-reader@example.test');
    grantNativeProductRoles($reader, ['viewer']);
    actAsEnvironmentAdmin($subject, $owner['envId']);
    nextRequest();
    $this->get(route('environment.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('canManageEnvironment', false)->where('quickActions', [])->where('checklist', null));
    $this->get(route('environment.users.show', $user->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('canManageEnvironment', false)->where('user.email', 'readable@example.test'));
    $this->patch(route('environment.users.update', $user->id), ['name' => 'Forbidden'])->assertForbidden();
    expect(User::query()->findOrFail($user->id)->name)->toBe('Readable');
});

it('denies project pages and writes without native operational authority despite all-environment selection', function (array $roles): void {
    $account = provisionAccount();
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Admin, 'project-denied@example.test');
    grantNativeProductRoles($member, $roles);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, true, [], $rows->version($member));
    signInAsMember($subject);
    $this->get(route('orgs.projects'))->assertForbidden();
    $this->get(route('orgs.projects.show', $account['project']->id))->assertForbidden();
    $this->get(route('orgs.projects.create'))->assertForbidden();
    $this->post(route('orgs.projects.store'), ['name' => 'Forbidden'])->assertForbidden();
})->with(['billing only' => [['billing']], 'no app role' => [[]]]);

it('keeps native Reader project pages within selected rows and rechecks revoked access', function (): void {
    $account = provisionAccount();
    $other = app(TenantProvisioner::class)->addEnvironment($account['project'], 'Staging');
    $foreign = provisionAccount('foreign-project@example.test');
    [$member, $subject] = addMember($account['organization']->id, MembershipRole::Member, 'project-reader@example.test');
    grantNativeProductRoles($member, ['viewer']);
    $rows = app(NativeEnvironmentAccess::class);
    $rows->replace($member, false, [$other->id], $rows->version($member));
    signInAsMember($subject);
    $this->get(route('orgs.projects'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('projects', 1)->where('projects.0.used', 1)->has('projects.0.environments', 1)
        ->where('projects.0.environments.0.id', $other->id)->where('canManage', false));
    $this->get(route('orgs.projects.show', $account['project']->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('environments', 1)->where('environments.0.id', $other->id)->where('canManage', false)->where('remaining', 0)->where('billingHref', null));
    $this->get(route('orgs.projects.show', $foreign['project']->id))->assertNotFound();
    $this->get(route('orgs.projects.create'))->assertForbidden();
    $rows->replace($member, false, [], $rows->version($member));
    nextRequest();
    $this->get(route('orgs.projects'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('projects', 0));
    $this->get(route('orgs.projects.show', $account['project']->id))->assertForbidden();
    $roles = app(NativeProductMembers::class);
    $roles->replace($member, [], $roles->access($member)['roles_version'], AuditActor::system());
    nextRequest();
    $this->get(route('orgs.projects'))->assertForbidden();
});

it('links project account access only for live native Billing authority in the exact organization', function (): void {
    config(['billing.enabled' => true]);
    $account = provisionAccount();
    signInAsMember($account['subjectId']);
    $this->get(route('orgs.projects.show', $account['project']->id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('billingHref', route('orgs.settings.billing', ['organization' => $account['organization']->id])));
});
