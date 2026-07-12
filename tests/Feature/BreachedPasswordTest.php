<?php

declare(strict_types=1);

use App\Platform\BreachedPasswords;
use App\Rules\NotBreached;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

function hibpFake(string $body): void
{
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response($body, 200),
    ]);
}

test('isBreached returns true when the suffix is present with a positive count', function () {
    $hash = strtoupper(sha1('password'));
    $suffix = substr($hash, 5);

    hibpFake("003D68EB55068C33ACE09247EE4C639306B:3\n{$suffix}:37359195\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:1");

    expect(app(BreachedPasswords::class)->isBreached('password'))->toBeTrue();
});

test('isBreached returns false when the suffix is absent from the response', function () {
    $strong = 'X9!q'.bin2hex(random_bytes(16)).'zK';
    $ourSuffix = substr(strtoupper(sha1($strong)), 5);

    // A body that deliberately excludes our suffix.
    hibpFake("003D68EB55068C33ACE09247EE4C639306B:3\n1234567890123456789012345678901234A:9");

    expect($ourSuffix)->not->toBe('1234567890123456789012345678901234A');
    expect(app(BreachedPasswords::class)->isBreached($strong))->toBeFalse();
});

test('isBreached fails open when HIBP returns a server error', function () {
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('Service Unavailable', 500),
    ]);

    expect(app(BreachedPasswords::class)->isBreached('password'))->toBeFalse();
});

test('isBreached fails open when the HIBP request throws', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    expect(app(BreachedPasswords::class)->isBreached('password'))->toBeFalse();
});

test('NotBreached rule adds an error for a breached password', function () {
    $suffix = substr(strtoupper(sha1('password')), 5);

    hibpFake("{$suffix}:37359195");

    $validator = Validator::make(
        ['password' => 'password'],
        ['password' => [new NotBreached]],
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('password'))
        ->toContain('known data breach');
});

test('NotBreached rule passes for a safe password', function () {
    $strong = 'X9!q'.bin2hex(random_bytes(16)).'zK';

    // Response that does not contain our suffix.
    hibpFake('1234567890123456789012345678901234A:9');

    $validator = Validator::make(
        ['password' => $strong],
        ['password' => [new NotBreached]],
    );

    expect($validator->passes())->toBeTrue();
});
