<?php

declare(strict_types=1);

use App\Actions\Platform\Workspaces\SetWorkspaceStatus;
use App\Mail\PasswordResetMail;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeDelegatedTokens;

/*
|--------------------------------------------------------------------------
| The operator plane: the deployment itself, as actions — reached by a platform operator
| and nobody else. No key of any kind gets through the door.
|--------------------------------------------------------------------------
|
| The console's Platform pages run these actions as the signed-in operator; the REST door
| (`/api/v1/platform`) runs them for an operator's DELEGATED token, which is a seam here:
| {@see FakeDelegatedTokens} stands in for the resolver delegated management tokens will
| bind. Until it is bound, every bearer is a 401.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();

    $this->operator = actAsOperator('ops@platform.test');
});

/** Every operator scope. */
const OPERATOR_SCOPES = ['operator:workspaces:write', 'operator:environments:write', 'operator:organizations:write', 'operator:operators:write'];

/**
 * An operator's delegated token for the signed-in operator, carrying $scopes.
 *
 * @param  list<string>  $scopes
 */
function operatorToken(PlatformOperator $operator, array $scopes = OPERATOR_SCOPES): string
{
    FakeDelegatedTokens::install()->operator('op-token', $operator->id, (string) $operator->subject_id, $scopes);

    return 'op-token';
}

/** @return array{0: string, 1: string} A path for every platform action, and its method. */
function platformRoutes(): array
{
    $routes = [];

    foreach (app(ActionRegistry::class)->forPlane(ActionPlane::Platform) as $action) {
        $routes[] = [$action->method, '/api/v1'.preg_replace('/\{\w+\}/', '01JNOTANIDATALL00000000000', $action->documentedPath())];
    }

    return $routes;
}

it('refuses every key and every bearer it does not know, before a delegated resolver exists', function (): void {
    $account = provisionAccount();
    $workspaceKey = app(OrganizationApiKeys::class)->issue($account['organization']->id, 'Owner key', MembershipRole::Owner)->plaintext;
    $environmentKey = app(EnvironmentApiKeys::class)->issue($account['environment']->id, 'Env key', ['users:write', 'organizations:write'])->plaintext;

    expect(platformRoutes())->not->toBeEmpty();

    foreach (platformRoutes() as [$method, $path]) {
        // No credential, a workspace key, an environment key, a made-up bearer: 401 each,
        // and the body is never read.
        $this->json($method, $path, ['name' => 'x'])->assertUnauthorized();

        foreach ([$workspaceKey, $environmentKey, 'anything-at-all'] as $bearer) {
            $this->withToken($bearer)->json($method, $path, ['name' => 'x'])
                ->assertUnauthorized()
                ->assertJsonPath('error', 'unauthorized');
        }
    }

    // …and nothing was created by any of it.
    expect(app(PlatformRoot::class)->run(fn (): int => Organization::query()->where('name', 'x')->count()))->toBe(0);
})->group('security');

it('refuses a person who is not an operator, and an operator token without the scope', function (): void {
    ['subjectId' => $member] = provisionAccount('member@acme.example');

    FakeDelegatedTokens::install()
        ->person('member-token', $member, OPERATOR_SCOPES)
        ->operator('narrow-token', $this->operator->id, (string) $this->operator->subject_id, ['operator:workspaces:write']);

    foreach (platformRoutes() as [$method, $path]) {
        $this->withToken('member-token')->json($method, $path)
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a platform operator can use this API.');
    }

    $this->withToken('narrow-token')->postJson('/api/v1/platform/operators', ['name' => 'X', 'email' => 'x@platform.test', 'password' => 'a-long-unbreached-pass'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This token is missing the required scope: operator:operators:write.');

    expect(PlatformOperator::query()->where('email', 'x@platform.test')->exists())->toBeFalse();
})->group('security');

it('never runs an operator action for a console member who is not an operator', function (): void {
    ['subjectId' => $member, 'organization' => $workspace] = provisionAccount('member@acme.example');

    forgetSubjectSession();
    signInAsMember($member);

    expect(fn () => app(ActionRunner::class)->run(
        SetWorkspaceStatus::class,
        new ConsoleSessionPrincipal(app(ConsoleScope::class)),
        ['workspace_id' => $workspace->id, 'status' => 'suspended'],
    ))->toThrow(AuthorizationException::class);

    expect(Organization::query()->whereKey($workspace->id)->value('status'))->toBe(OrganizationStatus::Active);
})->group('security');

it('stands up a workspace over the API and emails its owner a link, never a password', function (): void {
    $token = operatorToken($this->operator);

    $created = $this->withToken($token)->postJson('/api/v1/platform/workspaces', [
        'name' => 'Northwind',
        'owner_email' => 'Ada@Northwind.example',
        'owner_name' => 'Ada Lovelace',
        'environment_limit' => 3,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Northwind')
        ->assertJsonPath('data.owner.email', 'ada@northwind.example')
        ->assertJsonPath('data.owner_invited', true);

    expect($created->json('data.project_id'))->toBeString()
        ->and(Environment::query()->whereKey($created->json('data.environment_id'))->value('project_id'))->toBe($created->json('data.project_id'));

    Mail::assertSent(PasswordResetMail::class, fn ($mail): bool => $mail->hasTo('ada@northwind.example'));

    // An allowance the console does not offer is refused, as the form refuses it.
    $this->withToken($token)->postJson('/api/v1/platform/workspaces', [
        'name' => 'Odd', 'owner_email' => 'odd@odd.example', 'owner_name' => 'Odd', 'environment_limit' => 4,
    ])->assertUnprocessable()->assertJsonPath('error', 'invalid_limit');
});

it('suspends a workspace over the API exactly as the console does, recorded as the operator', function (): void {
    $workspace = provisionAccount()['organization'];
    $token = operatorToken($this->operator);

    // The console's own toggle first — the trail it writes is the reference.
    toggleCustomer($workspace->id);
    toggleCustomer($workspace->id);

    $this->withToken($token)->putJson("/api/v1/platform/workspaces/{$workspace->id}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    // Asking for the state it already has changes nothing and records nothing — the state,
    // not a flip, so a retry never undoes itself.
    $this->withToken($token)->putJson("/api/v1/platform/workspaces/{$workspace->id}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $suspensions = AuditEntry::query()->where('action', 'organization.suspended')->where('target_id', $workspace->id)->orderBy('sequence')->get();

    expect($suspensions)->toHaveCount(2)
        ->and($suspensions->pluck('actor_type')->unique()->all())->toBe([ActorType::Operator])
        ->and($suspensions->pluck('actor_id')->unique()->all())->toBe([$this->operator->id]);

    $this->withToken($token)->putJson("/api/v1/platform/workspaces/{$workspace->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('replays a retried write with the same Idempotency-Key instead of making it twice', function (): void {
    $token = operatorToken($this->operator);
    $body = ['name' => 'Staging cluster'];

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'env-1')->postJson('/api/v1/platform/environments', $body)->assertCreated();
    $again = $this->withToken($token)->withHeader('Idempotency-Key', 'env-1')->postJson('/api/v1/platform/environments', $body)->assertCreated();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Environment::query()->where('name', 'Staging cluster')->count())->toBe(1);
});

it('creates and bootstraps an environment, and runs the organizations inside it', function (): void {
    $token = operatorToken($this->operator);

    $environment = $this->withToken($token)->postJson('/api/v1/platform/environments', ['name' => 'Partner', 'domain' => 'id.partner.example'])
        ->assertCreated()
        // The domain is checked, never written: it is verified by DNS later.
        ->assertJsonPath('data.domain', null)
        ->json('data.id');

    $this->withToken($token)->postJson("/api/v1/platform/environments/{$environment}/provision", [
        'organization_name' => 'Partner Co',
        'admin_name' => 'Pat Admin',
        'admin_email' => 'pat@partner.example',
        'admin_password' => 'a-long-enough-unbreached-passphrase',
    ])->assertCreated()
        ->assertJsonPath('data.environment_id', $environment)
        ->assertJsonPath('data.organization.name', 'Partner Co')
        ->assertJsonPath('data.admin.email', 'pat@partner.example')
        ->assertJsonMissingPath('data.admin.password');

    // Asked again, the email is that environment's already.
    $this->withToken($token)->postJson("/api/v1/platform/environments/{$environment}/provision", [
        'organization_name' => 'Again', 'admin_name' => 'Pat', 'admin_email' => 'pat@partner.example', 'admin_password' => 'a-long-enough-unbreached-passphrase',
    ])->assertUnprocessable()->assertJsonPath('error', 'email_taken');

    $parent = $this->withToken($token)->postJson("/api/v1/platform/environments/{$environment}/organizations", ['name' => 'Reseller', 'type' => 'reseller'])
        ->assertCreated()
        ->assertJsonPath('data.environment_id', $environment)
        ->json('data.id');
    $child = $this->withToken($token)->postJson("/api/v1/platform/environments/{$environment}/organizations", ['name' => 'Child', 'type' => 'customer'])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($token)->putJson("/api/v1/platform/environments/{$environment}/organizations/{$child}/parent", ['parent_id' => $parent])
        ->assertOk()
        ->assertJsonPath('data.parent_id', $parent);

    // A cycle is refused, and nothing moves.
    $this->withToken($token)->putJson("/api/v1/platform/environments/{$environment}/organizations/{$parent}/parent", ['parent_id' => $child])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'cycle');

    $this->withToken($token)->putJson("/api/v1/platform/environments/{$environment}/organizations/{$child}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $entry = AuditEntry::query()->withoutGlobalScopes()->where('action', 'organization.suspended')->where('target_id', $child)->first();

    expect($entry?->actor_id)->toBe($this->operator->id);
});

it('answers 404 for anything that is not on this deployment, or not in the environment named', function (): void {
    $token = operatorToken($this->operator);
    $unknown = '01JNOTANIDATALL00000000000';

    // Two environments, an organization in each.
    $a = provisionAccount('a@a.example')['environment'];
    $b = provisionAccount('b@b.example')['environment'];
    $inA = app(EnvironmentContext::class)->runAs($a, fn (): Organization => app(Organizations::class)->create(new NewOrganization('In A', 'in-a')));
    $inB = app(EnvironmentContext::class)->runAs($b, fn (): Organization => app(Organizations::class)->create(new NewOrganization('In B', 'in-b')));

    $this->withToken($token)->putJson("/api/v1/platform/workspaces/{$unknown}/status", ['status' => 'suspended'])->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/platform/environments/{$unknown}/provision", [
        'organization_name' => 'X', 'admin_name' => 'X', 'admin_email' => 'x@x.example', 'admin_password' => 'a-long-enough-unbreached-passphrase',
    ])->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/platform/environments/{$unknown}/organizations", ['name' => 'X', 'type' => 'customer'])->assertNotFound();
    $this->withToken($token)->putJson("/api/v1/platform/operators/{$unknown}/status", ['status' => 'suspended'])->assertNotFound();

    // An organization of environment B, named under environment A, is not there — and a
    // parent from B is not spliced into A's tree.
    $this->withToken($token)->putJson("/api/v1/platform/environments/{$a->id}/organizations/{$inB->id}/status", ['status' => 'suspended'])->assertNotFound();
    $this->withToken($token)->putJson("/api/v1/platform/environments/{$a->id}/organizations/{$inA->id}/parent", ['parent_id' => $inB->id])->assertNotFound();
    $this->withToken($token)->postJson("/api/v1/platform/environments/{$a->id}/organizations", ['name' => 'Y', 'type' => 'customer', 'parent_id' => $inB->id])->assertNotFound();

    expect(app(EnvironmentContext::class)->runAs($b, fn () => app(Organizations::class)->find($inB->id)?->status))->toBe(OrganizationStatus::Active)
        ->and(app(EnvironmentContext::class)->runAs($a, fn () => app(Organizations::class)->find($inA->id)?->parent_id))->toBeNull();
})->group('security');

it('keeps the operator roster: adds one, never suspends yourself', function (): void {
    $token = operatorToken($this->operator);

    $second = $this->withToken($token)->postJson('/api/v1/platform/operators', [
        'name' => 'Second', 'email' => 'second@platform.test', 'password' => 'a-long-unbreached-pass',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.password')
        ->json('data.id');

    $this->withToken($token)->postJson('/api/v1/platform/operators', [
        'name' => 'Again', 'email' => 'second@platform.test', 'password' => 'a-long-unbreached-pass',
    ])->assertUnprocessable()->assertJsonPath('error', 'operator_exists');

    $this->withToken($token)->putJson("/api/v1/platform/operators/{$this->operator->id}/status", ['status' => 'suspended'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'self_suspension');

    $this->withToken($token)->putJson("/api/v1/platform/operators/{$second}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect(app(PlatformOperators::class)->findByEmail('second@platform.test')?->isActive())->toBeFalse()
        ->and(app(PlatformOperators::class)->findByEmail('ops@platform.test')?->isActive())->toBeTrue();
});

it('serves the operator API\'s own contract, publicly', function (): void {
    $this->get('/api/v1/platform/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml')
        ->assertSee('Operator API');
});

it('sends a bookmarked customers page to the workspaces one', function (): void {
    $workspace = provisionAccount()['organization'];

    $this->get('/platform/customers?q=acme')->assertStatus(301)->assertRedirect('/platform/workspaces?q=acme');
    $this->get("/platform/customers/{$workspace->id}")->assertStatus(301)->assertRedirect("/platform/workspaces/{$workspace->id}");
});
