<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Danger;
use App\Platform\Console\WebhookEventCatalogue;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\SignatureScheme;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\Id\Webhooks\Support\CboxWebhookSignature;
use Cbox\Id\Webhooks\Support\StandardWebhookSignature;

/*
|--------------------------------------------------------------------------
| Standard Webhooks, chosen per endpoint — and changed without a new secret.
|--------------------------------------------------------------------------
|
| `webhooks.create` takes `signature_scheme`; `webhooks.signature_scheme.change` moves an
| endpoint between `cbox` and `standard_webhooks`, keeping the secret its owner holds. What
| the console and the docs promise about that secret — a hex secret is `whsec_` + base64
| of itself — is held here against the framework's own verifier, so the copy cannot drift
| from what a receiver actually has to do.
*/

/** @param  list<string>  $scopes */
function schemeKey(array $scopes = ['webhooks:read', 'webhooks:write']): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Scheme worker', $scopes)->plaintext;
}

function schemeEvent(): string
{
    return WebhookEventCatalogue::offered()[0];
}

/** @return list<AuditEntry> */
function schemeTrail(string $action): array
{
    return array_values(AuditEntry::query()->where('action', $action)->orderBy('id')->get()->all());
}

function schemeSecretOf(string $id): string
{
    $endpoint = WebhookEndpoint::query()->findOrFail($id);

    return app(SecretBox::class)->open($endpoint->secret_encrypted, $endpoint->secretContext());
}

it('registers a Standard Webhooks endpoint with a whsec_ secret, and a Cbox one by default', function (): void {
    $key = schemeKey();

    $standard = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://a.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
        'signature_scheme' => 'standard_webhooks',
    ])->assertCreated()->json('data');

    $cbox = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://b.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
    ])->assertCreated()->json('data');

    expect($standard['signature_scheme'])->toBe('standard_webhooks')
        ->and($standard['secret'])->toStartWith('whsec_')
        ->and($cbox['signature_scheme'])->toBe('cbox')
        ->and($cbox['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(WebhookEndpoint::query()->whereKey($standard['id'])->value('signature_scheme'))->toBe(SignatureScheme::StandardWebhooks);

    $listed = collect($this->withToken($key)->getJson('/api/v1/webhooks')->assertOk()->json('data'))->pluck('signature_scheme', 'id');

    expect($listed[$standard['id']])->toBe('standard_webhooks')
        ->and($listed[$cbox['id']])->toBe('cbox');

    [$entry] = schemeTrail('webhook.created');

    expect($entry->context['signature_scheme'])->toBe('standard_webhooks');
});

it('refuses a scheme it does not know', function (): void {
    $this->withToken(schemeKey())->postJson('/api/v1/webhooks', [
        'url' => 'https://a.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
        'signature_scheme' => 'github',
    ])->assertUnprocessable();

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('moves an endpoint to Standard Webhooks without minting a secret, and the hex secret verifies as whsec_ + base64', function (): void {
    $key = schemeKey();
    $created = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://a.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
    ])->assertCreated()->json('data');
    $sealed = WebhookEndpoint::query()->whereKey($created['id'])->value('secret_encrypted');

    $changed = $this->withToken($key)->postJson("/api/v1/webhooks/{$created['id']}/signature-scheme", ['signature_scheme' => 'standard_webhooks'])
        ->assertOk()
        ->assertJsonPath('data.signature_scheme', 'standard_webhooks')
        ->json('data');

    // No secret on the answer, and the stored one is the very same.
    expect($changed)->not->toHaveKey('secret')
        ->and(WebhookEndpoint::query()->whereKey($created['id'])->value('secret_encrypted'))->toBe($sealed)
        ->and(schemeSecretOf($created['id']))->toBe($created['secret']);

    // What the copy tells the receiver to do, verified by the framework's own verifier
    // against what the dispatcher signs with.
    $payload = '{"type":"member.joined"}';
    $now = time();
    $headers = StandardWebhookSignature::headers('msg_1', $now, $payload, StandardWebhookSignature::secretFor($created['secret']));

    StandardWebhookSignature::verify($payload, $headers, 'whsec_'.base64_encode($created['secret']), now: $now);

    [$entry] = schemeTrail('webhook.signature_scheme_changed');

    expect($entry->actor_type)->toBe(ActorType::Service)
        ->and($entry->target_id)->toBe($created['id'])
        ->and($entry->context['changes'])->toEqual(['signature_scheme' => ['from' => 'cbox', 'to' => 'standard_webhooks']]);

    // The same scheme again changes nothing and records nothing.
    $this->withToken($key)->postJson("/api/v1/webhooks/{$created['id']}/signature-scheme", ['signature_scheme' => 'standard_webhooks'])->assertOk();

    expect(schemeTrail('webhook.signature_scheme_changed'))->toHaveCount(1);
});

it('moves a Standard Webhooks endpoint back to Cbox, where its whsec_ secret keys the HMAC as written', function (): void {
    $key = schemeKey();
    $created = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://a.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
        'signature_scheme' => 'standard_webhooks',
    ])->json('data');

    $this->withToken($key)->postJson("/api/v1/webhooks/{$created['id']}/signature-scheme", ['signature_scheme' => 'cbox'])
        ->assertOk()->assertJsonPath('data.signature_scheme', 'cbox');

    $payload = '{"type":"member.joined"}';
    $now = time();

    CboxWebhookSignature::verify($payload, CboxWebhookSignature::headers($now, $payload, $created['secret']), $created['secret'], now: $now);

    expect(schemeSecretOf($created['id']))->toBe($created['secret']);
});

it('rotates a Standard Webhooks endpoint to a fresh whsec_ secret', function (): void {
    $key = schemeKey();
    $id = $this->withToken($key)->postJson('/api/v1/webhooks', [
        'url' => 'https://a.acme.example/in',
        'event_types' => [schemeEvent()],
        'environment_wide' => true,
        'signature_scheme' => 'standard_webhooks',
    ])->json('data.id');

    $rotated = $this->withToken($key)->postJson("/api/v1/webhooks/{$id}/rotate")->assertOk()->json('data');

    expect($rotated['secret'])->toStartWith('whsec_')
        ->and($rotated['signature_scheme'])->toBe('standard_webhooks')
        ->and(schemeSecretOf($id))->toBe($rotated['secret']);
});

it('is destructive, needs webhooks:write, and never reaches another environment\'s endpoint', function (): void {
    expect(app(ActionRegistry::class)->named('webhooks.signature_scheme.change')->danger)->toBe(Danger::Destructive);

    $id = $this->withToken(schemeKey())->postJson('/api/v1/webhooks', ['url' => 'https://a.acme.example/in', 'event_types' => [schemeEvent()], 'environment_wide' => true])->json('data.id');
    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): string => app(WebhookRegistry::class)
        ->registerForEnvironment('https://other.acme.example/in', [schemeEvent()])->endpoint->id);

    $this->withToken(schemeKey(['webhooks:read']))->postJson("/api/v1/webhooks/{$id}/signature-scheme", ['signature_scheme' => 'standard_webhooks'])->assertForbidden();
    $this->withToken(schemeKey())->postJson("/api/v1/webhooks/{$foreign}/signature-scheme", ['signature_scheme' => 'standard_webhooks'])->assertNotFound();
    $this->withToken(schemeKey())->postJson("/api/v1/webhooks/{$id}/signature-scheme", ['signature_scheme' => 'nope'])->assertUnprocessable();

    expect(WebhookEndpoint::query()->whereKey($id)->value('signature_scheme'))->toBe(SignatureScheme::Cbox)
        ->and(app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): mixed => WebhookEndpoint::query()->whereKey($foreign)->value('signature_scheme')))->toBe(SignatureScheme::Cbox);
})->group('security');

it('lets an organization administrator choose and change the scheme from the console, as themselves', function (): void {
    config(['cbox-id.webhooks.verify_url' => false]);

    $subject = app(Subjects::class)->create('scheme@acme.test', 'Scheme Admin', 'supersecret123');
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-scheme'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    $own = app(WebhookRegistry::class)->register($org->id, 'https://own.acme.example/in', [schemeEvent()])->endpoint;
    $environments = app(WebhookRegistry::class)->registerForEnvironment('https://env.acme.example/in', [schemeEvent()])->endpoint;

    $from = route('webhooks.show', $own->id);

    $this->get($from)->assertOk()->assertInertia(fn ($page) => $page
        ->where('endpoint.signatureScheme', 'cbox')
        ->has('urls.scheme'));

    $this->from($from)->post(route('webhooks.scheme', $own->id), ['signatureScheme' => 'standard_webhooks'])
        ->assertRedirect($from)->assertSessionHasNoErrors();
    $this->from($from)->post(route('webhooks.scheme', $own->id), ['signatureScheme' => 'bogus'])
        ->assertRedirect($from)->assertSessionHasErrors('signatureScheme');

    // The environment's own endpoint is visible to a tenant, never changeable by one.
    $this->post(route('webhooks.scheme', $environments->id), ['signatureScheme' => 'standard_webhooks'])->assertForbidden();

    $entries = schemeTrail('webhook.signature_scheme_changed');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($subject->id)
        ->and($entries[0]->organization_id)->toBe($org->id)
        ->and(WebhookEndpoint::query()->whereKey($own->id)->value('signature_scheme'))->toBe(SignatureScheme::StandardWebhooks)
        ->and(WebhookEndpoint::query()->whereKey($environments->id)->value('signature_scheme'))->toBe(SignatureScheme::Cbox);

    // Registered from the form with the scheme chosen there.
    app(Sudo::class)->confirm();
    $this->post(route('webhooks.store'), ['url' => 'https://new.acme.example/in', 'eventTypes' => [schemeEvent()], 'signatureScheme' => 'standard_webhooks'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(WebhookEndpoint::query()->where('url', 'https://new.acme.example/in')->value('signature_scheme'))->toBe(SignatureScheme::StandardWebhooks);

    WebhookEndpoint::query()->where('url', 'https://new.acme.example/in')->delete();

    $rows = $this->get(route('webhooks'))->assertOk()->viewData('page')['props']['endpoints'];

    expect(collect($rows)->pluck('signatureScheme', 'id')->all())->toBe([
        $environments->id => 'cbox',
        $own->id => 'standard_webhooks',
    ]);
})->group('security');
