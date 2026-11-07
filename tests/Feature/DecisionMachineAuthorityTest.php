<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\ValueObjects\ActingParty;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->decisionClient = app(ClientRegistry::class)->register(new NewClient(
        name: 'Mixed login and decision client',
        grantTypes: ['authorization_code', 'client_credentials'],
        scopes: ['openid', 'decisions:read'],
    ))->client;
    $this->decisionUser = app(Subjects::class)->create('decision-human@example.test', 'Human');
});

it('keeps genuine machine decisions scoped to their own application', function (): void {
    $token = app(TokenIssuer::class)->issueClientCredentials($this->decisionClient, ['decisions:read']);
    $this->withToken($token->token)->postJson('/oauth/decisions', [
        'subject' => $this->decisionUser->id, 'permission' => 'records:read', 'client_id' => 'foreign-app',
    ])->assertOk()->assertJsonPath('client_id', $this->decisionClient->client_id);
});

it('permits human self decisions but refuses another subject even with the decision scope', function (): void {
    $token = app(TokenIssuer::class)->issueForUser($this->decisionClient, $this->decisionUser->id, null, ['decisions:read']);
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read'])->assertOk();
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read', 'subject' => 'another-person'])->assertForbidden();
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read', 'org' => 'another-org'])->assertForbidden();
});

it('refuses human and acted issuance that collides with the service identity', function (bool $acted): void {
    $this->decisionClient->update(['client_id' => $this->decisionUser->id]);
    $issuer = app(TokenIssuer::class);
    $token = $acted
        ? $issuer->issueActing($this->decisionClient, $this->decisionUser->id, null, ['decisions:read'], new ActingParty('support-actor', 'support-session'), now()->addMinutes(5))
        : $issuer->issueForUser($this->decisionClient, $this->decisionUser->id, null, ['decisions:read']);
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read', 'subject' => 'another-person', 'org' => 'another-org'])
        ->assertForbidden()->assertJsonPath('error', 'machine_token_required');
})->with([false, true]);

it('keeps acted human decisions bound to the supported subject and organization', function (): void {
    $token = app(TokenIssuer::class)->issueActing($this->decisionClient, $this->decisionUser->id, null,
        ['decisions:read'], new ActingParty('support-actor', 'support-session'), now()->addMinutes(5));
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read'])->assertOk();
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read', 'subject' => 'another-person'])->assertForbidden();
    $this->withToken($token->token)->postJson('/oauth/decisions', ['permission' => 'records:read', 'org' => 'another-org'])->assertForbidden();
});

it('preserves machine scope refusal and expired token refusal', function (): void {
    $token = app(TokenIssuer::class)->issueClientCredentials($this->decisionClient, ['openid']);
    $body = ['subject' => $this->decisionUser->id, 'permission' => 'records:read'];
    $this->withToken($token->token)->postJson('/oauth/decisions', $body)->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
    $this->travel(2)->days();
    $this->withToken($token->token)->postJson('/oauth/decisions', $body)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
