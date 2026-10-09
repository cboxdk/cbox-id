<?php

declare(strict_types=1);

use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Enums\PortalIntent;
use App\Platform\Enums\PortalScope;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\PlatformAuth;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CrossTenantSweep;
use Tests\Support\FakeOperatorToken;
use Tests\Support\FakePersonToken;

/*
|--------------------------------------------------------------------------
| Somebody else's id answers 404 — by construction, for every action.
|--------------------------------------------------------------------------
|
| An id in a URL is the caller's claim, never a fact. Every action that takes one is walked
| here from the registry, so an action added tomorrow is swept the day it lands: it is sent
| an id that exists — in ANOTHER ENVIRONMENT; for a principal confined to one organization,
| in ANOTHER ORGANIZATION of the same environment; on the workspace plane, another
| WORKSPACE's; on the account plane, another PERSON's — and it must answer the way it
| answers an id that never existed. 404. Not 403, which confirms the thing is there and is
| somebody else's; not 200, which is the breach itself.
|
| Each id field is swapped on its own, the others left the caller's own, and then all of
| them at once: `/organizations/{mine}/members/{theirs}` is the request that finds a lookup
| fenced on its parent and loose on its child.
|
| What cannot be swept generically is listed below with the reason, and the list is checked
| for staleness. See docs/security/console-action-sweep.md.
*/

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    Http::fake();
});

/** Another environment the sweep plants its foreign world in. */
const SWEEP_FOREIGN_ENVIRONMENT = 'env_sweep_foreign';

/**
 * Actions the generic sweep cannot drive, with the reason — and where the guarantee is
 * held instead.
 *
 * @var array<string, string>
 */
const SWEEP_UNSWEPT = [
    // Its id is a NAME the caller chooses, and the request defines it: there is no foreign
    // one. The parent `{id}` is swept like any other.
    'apis.scopes.define' => 'PUT defines the scope {key} names; the parent API id is swept by apis.scopes.remove.',
    // A platform operator administers EVERY environment, workspace and operator of the
    // deployment: an id of one of those is never somebody else's to an operator. What can be
    // foreign is a PAIR — an organization named under an environment it is not in — and the
    // two actions that take one are swept below.
    'platform.environments.provision' => 'an operator reaches every environment; nothing is foreign to them.',
    'platform.operators.set_status' => 'an operator administers every operator; nothing is foreign to them.',
    'platform.organizations.create' => 'an operator reaches every environment; nothing is foreign to them.',
    'platform.workspaces.set_status' => 'an operator administers every workspace; nothing is foreign to them.',
    'audit_logs.schemas.update' => 'PUT upserts the schema {action} names; a name is not an id. GET and DELETE are swept.',
];

/**
 * Path fields whose answer to an unknown id is deliberately NOT a 404, and where a foreign id
 * must therefore answer EXACTLY as an unknown one does — checked against a made-up id on every
 * run, and never a 403 or a 2xx. The property under test is that somebody else's id is
 * indistinguishable from no id; 404 is how almost every action says that.
 *
 * @var array<string, string> "action:field" => why
 */
const SWEEP_AS_UNKNOWN = [
    // From inside an organization, a role it is not offered — missing, a staff role, or a
    // peer's own — is one answer, "not offered" on the field, so a tenant cannot enumerate
    // the roles outside its catalogue and the console keeps it as a form error.
    'members.roles.grant:role_id' => 'a role a tenant is not offered answers role_not_assignable, whether it exists or not.',
    'members.roles.revoke:role_id' => 'a role a tenant is not offered answers role_not_assignable, whether it exists or not.',
];

/**
 * A valid value for one JSON Schema, so validation passes and the action reaches its lookup.
 */
function sweepValue(array $schema, string $name): mixed
{
    $type = is_array($schema['type'] ?? null) ? $schema['type'][0] : ($schema['type'] ?? 'string');

    if (isset($schema['enum'])) {
        return $schema['enum'][0];
    }

    return match ($type) {
        'integer' => max((int) ($schema['minimum'] ?? 1), 1),
        'boolean' => false,
        'array' => array_fill(0, max((int) ($schema['minItems'] ?? 1), 1), sweepValue((array) ($schema['items'] ?? ['type' => 'string']), $name)),
        'object' => sweepObject($schema),
        default => match ($schema['format'] ?? null) {
            'email' => 'sweep@sweep.example',
            'uri' => 'https://sweep.example/'.$name,
            default => str_pad('sweep', (int) ($schema['minLength'] ?? 0), 'x'),
        },
    };
}

/** @return array<string, mixed> */
function sweepObject(array $schema): array
{
    $object = [];

    foreach ((array) ($schema['required'] ?? []) as $required) {
        $object[$required] = sweepValue((array) ($schema['properties'][$required] ?? []), (string) $required);
    }

    return $object;
}

/**
 * Bodies the schema alone cannot make valid: a business rule an action checks before its
 * lookup would answer 422 and the lookup would go unswept.
 *
 * @return array<string, mixed>
 */
function sweepBody(ActionDefinition $action, array $own): array
{
    $schema = $action->input()->jsonSchema();
    $body = [];

    // An action that narrows to an organization named in the body is asked about the
    // caller's own: the id in the URL is what is foreign, never the frame around it.
    if (isset($schema['properties']['organization_id']) && ! in_array('organization_id', $action->input()->pathFields(), true)) {
        $body['organization_id'] = $own['organization'];
    }

    foreach ((array) ($schema['required'] ?? []) as $field) {
        if (! in_array($field, $action->input()->pathFields(), true)) {
            $body[$field] = sweepValue((array) $schema['properties'][$field], (string) $field);
        }
    }

    return [...$body, ...(match ($action->name) {
        'account.api_keys.create' => ['client_id' => $own['application'] ?? '', 'name' => 'Sweep key'],
        'apps.scopes.set' => ['scopes' => ['openid']],
        'apps.copy' => ['environment_id' => 'env_test', 'name' => 'Copy'],
        'apps.secrets.rotate' => ['grace_seconds' => 0],
        'directories.credentials.replace' => ['credentials' => ['api_key' => 'sweep']],
        'directories.groups.map' => ['group_id' => $own['directory_group'], 'role_id' => $own['role'], 'mapped' => true],
        'frontend_keys.set_origins' => ['origins' => ['https://sweep.example']],
        'invitations.send' => ['email' => 'sweep-invitee@sweep.example'],
        'organizations.portal_links.create' => ['intents' => ['sso']],
        'sso.connections.certificates.activate' => ['fingerprint_sha256' => str_repeat('a', 64)],
        'token_vault.secrets.rotate' => ['secret' => 'rotated'],
        'token_vault.grants.create' => ['client_id' => 'sweep-agent'],
        'users.password.set' => ['password' => 'a-strong-unbreached-passphrase', 'reason' => 'sweep'],
        default => [],
    })];
}

/**
 * Every request the sweep sends one action: each id field foreign on its own, then all of
 * them at once. A field naming a NAME rather than an id stays the caller's own.
 *
 * @param  array<string, string>  $own
 * @param  array<string, string>  $foreign
 * @return array<string, array<string, string>> label => path values
 */
function sweepVariants(ActionDefinition $action, array $own, array $foreign): array
{
    $fields = $action->input()->pathFields();
    $slots = [];

    foreach ($fields as $field) {
        $slots[$field] = CrossTenantSweep::slot($action, $field);
    }

    $mine = [];

    foreach ($slots as $field => $slot) {
        $mine[$field] = $own[$slot];
    }

    $variants = [];
    $all = $mine;

    foreach ($slots as $field => $slot) {
        if (in_array($slot, CrossTenantSweep::NAMES, true)) {
            continue;
        }

        $variants["{{$field}} foreign"] = [...$mine, $field => $foreign[$slot]];
        $all[$field] = $foreign[$slot];
    }

    if (count($variants) > 1) {
        $variants['every id foreign'] = $all;
    }

    return $variants;
}

/** @return array<string, string> every path field the caller's own */
function sweepOwnValues(ActionDefinition $action, array $own): array
{
    $values = [];

    foreach ($action->input()->pathFields() as $field) {
        $values[$field] = $own[CrossTenantSweep::slot($action, $field)];
    }

    return $values;
}

function sweepUrl(ActionDefinition $action, array $values): string
{
    $path = $action->documentedPath();

    foreach ($values as $field => $value) {
        $path = str_replace('{'.$field.'}', rawurlencode($value), $path);
    }

    return '/api/v1'.$path;
}

/*
|--------------------------------------------------------------------------
| Another environment's id
|--------------------------------------------------------------------------
*/

/**
 * Sweep $actions as $principal against $own and $foreign, through the runner every door
 * shares ({@see CrossTenantSweep::status()} answers as the REST door would): each id-taking
 * action with every foreign variant — then the CONTROL, the caller's own ids, which must be
 * found, or a fixture pointing at nothing would make every 404 a pass for the wrong reason.
 * Each action runs in a savepoint rolled back after it, so what one deletes the next still has.
 *
 * @param  array<string, string>  $own
 * @param  array<string, string>  $foreign
 * @return array{wrong: list<string>, blind: list<string>, swept: int}
 */
function sweepAs(Principal $principal, string $who, array $own, array $foreign, ActionPlane $plane = ActionPlane::Environment): array
{
    $wrong = [];
    $blind = [];
    $swept = 0;

    foreach (CrossTenantSweep::idTakingActions($plane) as $action) {
        if (isset(SWEEP_UNSWEPT[$action->name])) {
            continue;
        }

        try {
            $principal->authorize($action);
        } catch (AuthorizationException) {
            continue; // Not this principal's to run at all, whatever the id.
        }

        // A signed-in token's critical actions wait for the person's approval before they
        // run, and so before any lookup; the console runs the same lookup unheld.
        if ($principal instanceof DelegatedTokenPrincipal && $action->danger === Danger::Critical) {
            continue;
        }

        $body = sweepBody($action, $own);
        DB::beginTransaction();

        foreach (sweepVariants($action, $own, $foreign) as $label => $values) {
            $status = CrossTenantSweep::status($action, $principal, [...$body, ...$values]);
            $swept++;
            $expected = 404;

            // A field that answers an unknown id some other way must answer a foreign one
            // the same way: asked with a made-up id in its place, here and now.
            foreach (array_diff_assoc($values, sweepOwnValues($action, $own)) as $field => $ignored) {
                if (isset(SWEEP_AS_UNKNOWN["{$action->name}:{$field}"]) && count(array_diff_assoc($values, sweepOwnValues($action, $own))) === 1) {
                    $expected = CrossTenantSweep::status($action, $principal, [...$body, ...$values, $field => (string) Str::ulid()]);
                }
            }

            if ($status !== $expected || $status === 403 || $status < 300) {
                $wrong[] = "{$who}: {$action->name} ({$label}): {$status}".($expected !== 404 ? " (an unknown id answers {$expected})" : '');
            }
        }

        $control = CrossTenantSweep::status($action, $principal, [...$body, ...sweepOwnValues($action, $own)]);

        if ($control === 404 || $control >= 500) {
            $blind[] = "{$who}: {$action->name}: {$control}";
        }

        DB::rollBack();
    }

    return ['wrong' => $wrong, 'blind' => $blind, 'swept' => $swept];
}

it('answers 404 to every id from another environment, for every action that takes one', function (): void {
    $foreign = CrossTenantSweep::world(SWEEP_FOREIGN_ENVIRONMENT, 'Foreign');
    $own = CrossTenantSweep::world('env_test', 'Own');
    $key = new EnvironmentKeyPrincipal(CrossTenantSweep::key('env_test', 'Sweep caller'));

    ['wrong' => $wrong, 'blind' => $blind, 'swept' => $swept] = sweepAs($key, 'environment key', $own, $foreign);

    expect($wrong)->toBe([], "Another environment's id must answer 404:\n".implode("\n", $wrong))
        ->and($blind)->toBe([], "The caller's own ids must be found, or the sweep proves nothing:\n".implode("\n", $blind))
        ->and($swept)->toBeGreaterThan(150);
})->group('security');

/**
 * The wire, for the reads: the REST door renders the same 404 envelope the runner's
 * refusal carries, with nothing about the other environment's row in it. Reads only —
 * every action's answer is the runner's, swept above; this is the door's rendering of it.
 */
it('renders another environment\'s id as the API\'s 404 envelope over REST', function (): void {
    $foreign = CrossTenantSweep::world(SWEEP_FOREIGN_ENVIRONMENT, 'Foreign');
    $own = CrossTenantSweep::world('env_test', 'Own');
    $token = app(EnvironmentApiKeys::class)->issue('env_test', 'Sweep caller', CrossTenantSweep::environmentScopes())->plaintext;
    $wrong = [];

    foreach (CrossTenantSweep::idTakingActions() as $action) {
        if ($action->danger !== Danger::Read || isset(SWEEP_UNSWEPT[$action->name])) {
            continue;
        }

        foreach (sweepVariants($action, $own, $foreign) as $label => $values) {
            $response = $this->withToken($token)->getJson(sweepUrl($action, $values).'?'.http_build_query(sweepBody($action, $own)));
            $this->flushHeaders();

            if ($response->status() !== 404 || $response->json('error') !== 'not_found') {
                $wrong[] = "{$action->name} ({$label}): {$response->status()} ".substr((string) $response->getContent(), 0, 160);
            }
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
})->group('security');

/*
|--------------------------------------------------------------------------
| Another organization's id, for a principal confined to one
|--------------------------------------------------------------------------
*/

/**
 * The person administering their own organization, in the organization console — the
 * principal every confined door shares its answer with.
 *
 * @return array{0: string, 1: Organization}
 */
function sweepOrganizationOwner(string $organizationId): array
{
    $subject = app(Subjects::class)->create('sweep-owner@sweep.example', 'Sweep Owner', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    app(Memberships::class)->add($organizationId, $subject->id, MembershipRole::Owner);
    $organization = Organization::query()->findOrFail($organizationId);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $session = app(SessionManager::class)->start($subject->id, $organization->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $organization, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $organization];
}

/**
 * @return array<string, Principal> label => a principal confined to $organization
 */
function sweepConfinedPrincipals(string $subjectId, Organization $organization): array
{
    // The Admin Portal runs what its link's intents list, while the plan includes them.
    foreach (PortalIntent::cases() as $intent) {
        if ($intent->entitlement() !== null) {
            grantFeature($organization->id, $intent->entitlement());
        }
    }

    return [
        'Admin Portal' => new PortalPrincipal('sweep-link', $organization->id, PortalScope::of(PortalIntent::cases()), 'sweep'),
        'organization console' => new ConsoleSessionPrincipal(app(ConsoleScope::class)),
        'signed-in token' => new DelegatedTokenPrincipal(
            subjectId: $subjectId,
            personName: 'Sweep Owner',
            clientId: 'sweep-cli',
            clientName: 'Sweep CLI',
            environmentId: 'env_test',
            scopes: CrossTenantSweep::environmentScopes(),
            organization: new OrganizationChoice($organization->id, $organization->name, MembershipRole::Owner),
            customerConsole: false,
        ),
    ];
}

it('answers 404 to every id from another organization, for every principal confined to one', function (): void {
    $theirs = CrossTenantSweep::world('env_test', 'Theirs');
    $mine = CrossTenantSweep::world('env_test', 'Mine');
    [$subjectId, $organization] = sweepOrganizationOwner($mine['organization']);

    $wrong = [];
    $blind = [];
    $swept = [];

    foreach (sweepConfinedPrincipals($subjectId, $organization) as $who => $principal) {
        $result = sweepAs($principal, $who, $mine, $theirs);
        $wrong = [...$wrong, ...$result['wrong']];
        $blind = [...$blind, ...$result['blind']];
        $swept[$who] = $result['swept'];
    }

    expect($wrong)->toBe([], "Another organization's id must answer 404 to a confined principal:\n".implode("\n", $wrong))
        ->and($blind)->toBe([], "The caller's own ids must be found, or the sweep proves nothing:\n".implode("\n", $blind));

    // Each confined principal reaches something to sweep — a principal that silently
    // authorized nothing would pass vacuously.
    foreach ($swept as $who => $count) {
        expect($count)->toBeGreaterThan(5, "{$who} swept {$count} requests");
    }
})->group('security');

/*
|--------------------------------------------------------------------------
| The sweep cannot quietly shrink
|--------------------------------------------------------------------------
*/

it('maps every id every action takes, on every plane, and lists nothing that no longer exists', function (): void {
    $names = [];

    foreach (ActionPlane::cases() as $plane) {
        foreach (CrossTenantSweep::idTakingActions($plane) as $action) {
            $names[] = $action->name;

            foreach ($action->input()->pathFields() as $field) {
                CrossTenantSweep::slot($action, $field); // throws, naming the action, when unmapped
            }
        }
    }

    expect(array_values(array_diff(array_keys(SWEEP_UNSWEPT), $names)))->toBe([])
        ->and(array_values(array_diff(array_map(static fn (string $key): string => strstr($key, ':', true) ?: $key, array_keys(SWEEP_AS_UNKNOWN)), $names)))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Another workspace's id, on the workspace plane
|--------------------------------------------------------------------------
*/

/**
 * A workspace with one of everything its plane names by id, and the key that runs it.
 *
 * @return array{0: Principal, 1: array<string, string>}
 */
function sweepWorkspace(string $email): array
{
    $account = provisionAccount($email);
    $workspace = $account['organization'];
    $slug = Str::lower(Str::random(6));
    $principal = new WorkspaceKeyPrincipal(app(OrganizationApiKeys::class)->issue($workspace->id, 'Sweep agent', MembershipRole::Owner)->key);
    $run = static fn (string $action, array $input): array => (array) app(ActionRunner::class)->run(app(ActionRegistry::class)->named($action), $principal, $input)->payload;

    $member = app(PlatformRoot::class)->run(static fn () => app(Memberships::class)->add(
        $workspace->id,
        app(Subjects::class)->create("dev-{$slug}@sweep.example", 'Dev')->id,
        MembershipRole::Developer,
    ));

    $world = [
        'environment' => $account['environment']->id,
        'project' => $account['project']->id,
        'team_member' => (string) $member->id,
        'team_invitation' => $run('team.invite', ['email' => "invitee-{$slug}@sweep.example", 'role' => 'developer'])['id'],
        'workspace_key' => (string) app(OrganizationApiKeys::class)->issue($workspace->id, 'Sweep other', MembershipRole::Viewer)->key->id,
        'environment_key' => $run('keys.environment.create', ['environment_id' => $account['environment']->id, 'name' => 'Sweep env key', 'scopes' => ['users:read']])['id'],
    ];

    $run('environments.domain.request', ['environment_id' => $world['environment'], 'domain' => "id.{$slug}.sweep.example"]);

    return [$principal, $world];
}

it('answers 404 to every id from another workspace, for every workspace action that takes one', function (): void {
    [$key, $mine] = sweepWorkspace('owner@mine.example');
    [, $theirs] = sweepWorkspace('owner@theirs.example');

    ['wrong' => $wrong, 'blind' => $blind, 'swept' => $swept] = sweepAs($key, 'workspace key', $mine, $theirs, ActionPlane::Workspace);

    expect($wrong)->toBe([], "Another workspace's id must answer 404:\n".implode("\n", $wrong))
        ->and($blind)->toBe([], "The caller's own ids must be found, or the sweep proves nothing:\n".implode("\n", $blind))
        ->and($swept)->toBeGreaterThan(14);
})->group('security');

/*
|--------------------------------------------------------------------------
| Another person's id, on the account plane
|--------------------------------------------------------------------------
*/

/**
 * One person's account: a session, a passkey, a device, an app key in a shared organization.
 *
 * @param  array{org: Organization, clientId: string}  $fixture
 * @return array<string, string>
 */
function sweepAccount(string $subjectId, array $fixture, string $organizationId): array
{
    $device = new Device;
    $device->fill(['subject_id' => $subjectId, 'install_id' => (string) Str::ulid(), 'platform' => DevicePlatform::Ios, 'name' => 'Sweep phone', 'status' => DeviceStatus::Active]);
    $device->save();
    app(Subjects::class)->link($subjectId, new FederatedPrincipal('social:github', 'github|'.$subjectId));

    return [
        'organization' => $organizationId,
        'session' => app(SessionManager::class)->start($subjectId, null, ['pwd'])->id,
        'passkey' => (string) WebAuthnCredential::query()->create(['user_id' => $subjectId, 'credential_id' => 'cred-'.$subjectId, 'public_key' => 'pk', 'name' => 'Sweep key', 'sign_count' => 0])->id,
        'device' => (string) $device->id,
        'customer_api_key' => (string) mintAppKey($fixture, $subjectId)->id,
        'application' => $fixture['clientId'],
        'social_provider' => 'github',
    ];
}

it('answers 404 to every id from another person, for every account action that takes one', function (): void {
    $fixture = appKeyFixture();
    $elsewhere = app(Organizations::class)->create(new NewOrganization('Elsewhere', 'sweep-elsewhere'));
    app(Memberships::class)->add($elsewhere->id, $fixture['bob'], MembershipRole::Member);

    $mine = sweepAccount($fixture['ada'], $fixture, $fixture['org']->id);
    // Bob's things — and an organization Ada is not a member of.
    $theirs = sweepAccount($fixture['bob'], $fixture, $elsewhere->id);
    $me = new FakePersonToken($fixture['ada'], AccountScopes::all(), $mine['session']);

    ['wrong' => $wrong, 'blind' => $blind, 'swept' => $swept] = sweepAs($me, 'my token', $mine, $theirs, ActionPlane::Account);

    expect($wrong)->toBe([], "Another person's id must answer 404:\n".implode("\n", $wrong))
        ->and($blind)->toBe([], "The caller's own ids must be found, or the sweep proves nothing:\n".implode("\n", $blind))
        ->and($swept)->toBeGreaterThan(5);
})->group('security');

/*
|--------------------------------------------------------------------------
| A mismatched pair, on the platform plane
|--------------------------------------------------------------------------
*/

it('answers 404 to an organization named under an environment it is not in', function (): void {
    $operator = actAsOperator('sweep-op@platform.test');
    $principal = new FakeOperatorToken($operator->id, (string) $operator->subject_id, ['operator:workspaces:write', 'operator:environments:write', 'operator:organizations:write', 'operator:operators:write']);
    $run = static fn (string $action, array $input): array => (array) app(ActionRunner::class)->run(app(ActionRegistry::class)->named($action), $principal, $input)->payload;

    $here = $run('platform.environments.create', ['name' => 'Sweep here'])['id'];
    $there = $run('platform.environments.create', ['name' => 'Sweep there'])['id'];
    $ours = $run('platform.organizations.create', ['environment_id' => $here, 'name' => 'Ours', 'type' => 'customer'])['id'];
    $theirs = $run('platform.organizations.create', ['environment_id' => $there, 'name' => 'Theirs', 'type' => 'customer'])['id'];

    $registry = app(ActionRegistry::class);
    $wrong = [];

    foreach (['platform.organizations.move' => ['parent_id' => null], 'platform.organizations.set_status' => ['status' => 'suspended']] as $name => $body) {
        $action = $registry->named($name);

        foreach (['their organization under our environment' => [$here, $theirs], 'our organization under their environment' => [$there, $ours]] as $label => [$environment, $organization]) {
            $status = CrossTenantSweep::status($action, $principal, [...$body, 'environment_id' => $environment, 'organization_id' => $organization]);

            if ($status !== 404) {
                $wrong[] = "{$name} ({$label}): {$status}";
            }
        }

        expect(CrossTenantSweep::status($action, $principal, [...$body, 'environment_id' => $here, 'organization_id' => $ours]))->not->toBe(404);
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
})->group('security');
