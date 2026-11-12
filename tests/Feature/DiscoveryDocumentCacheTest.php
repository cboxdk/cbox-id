<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The discovery document is built once a minute, not once a request
|--------------------------------------------------------------------------
|
| Measured on production (2026-11-12): GET /.well-known/openid-configuration took 73 ms in
| the pod after the connection and rate-limit fixes, of which 18 ms was building the
| document, two queries and four cache reads. Every Cbox app reads it when it starts a
| sign-in. The document changes when the issuer, a public scope or the sign-up setting
| changes, so it is kept for a minute per environment, issuer and host: an issuer change
| is a new document at once, the rest shows within the minute.
*/

/** @return list<string> the SQL sent while $callback ran */
function discoveryStatements(Closure $callback): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

it('answers the second request from the kept document, without asking for the scopes again', function (): void {
    $first = $this->getJson('/.well-known/openid-configuration')->assertOk()->json();

    $statements = discoveryStatements(function () use ($first): void {
        expect($this->getJson('/.well-known/openid-configuration')->assertOk()->json())->toBe($first);
    });

    expect($first['issuer'] ?? null)->toBeString()
        ->and(collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'oauth_api_scopes'))->all())->toBe([]);
});

it('is a new document at once when the issuer changes', function (): void {
    $before = $this->getJson('/.well-known/openid-configuration')->assertOk()->json('issuer');

    config(['cbox-id.issuer' => 'https://id.changed.example']);

    $after = $this->getJson('/.well-known/openid-configuration')->assertOk();

    expect($after->json('issuer'))->toBe('https://id.changed.example')->not->toBe($before)
        ->and($after->json('token_endpoint'))->toStartWith('https://id.changed.example/');
});

it('is built again after a minute', function (): void {
    $this->getJson('/.well-known/openid-configuration')->assertOk();

    $this->travel(61)->seconds();

    $statements = discoveryStatements(fn () => $this->getJson('/.well-known/openid-configuration')->assertOk());

    expect(collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'oauth_api_scopes'))->all())->not->toBe([]);
});

it('keeps one document per environment, issuer, host and release', function (): void {
    $keys = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$keys): void {
        if (str_starts_with($event->key, 'cbox-id:discovery:openid:')) {
            $keys[] = $event->key;
        }
    });

    $this->getJson('/.well-known/openid-configuration')->assertOk();
    config(['cbox-id.issuer' => 'https://id.changed.example']);
    $this->getJson('/.well-known/openid-configuration')->assertOk();
    config(['app.version' => 'next-release']);
    $this->getJson('/.well-known/openid-configuration')->assertOk();

    expect(array_unique($keys))->toHaveCount(3);
});
