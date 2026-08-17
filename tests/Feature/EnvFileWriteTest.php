<?php

declare(strict_types=1);

use App\Platform\Install\EnvFile;
use Dotenv\Dotenv;

/**
 * A value recorded in `.env` must be the value that was handed over.
 *
 * `set()` replaced the line with `preg_replace`, which puts the value in the REPLACEMENT
 * position — where `$1` and `\1` are backreferences and `\\` is an escape. So the writer
 * silently rewrote the very thing it was asked to record: an issuer of
 * `https://id.acme.com/$1/x` was written as `https://id.acme.com//x`.
 *
 * Latent, because nothing written today carries those characters — the crypto key is
 * base64 and the hosts are hostnames. Fixed rather than noted because the one value where
 * corruption is silent AND unrecoverable goes through this writer: `CBOX_ID_CRYPTO_KEY`,
 * whose own failure message says every sealed secret written during the install becomes
 * unreadable without it.
 *
 * The same class of bug as the install command printing a password through a console
 * formatter that eats `<tag>`: a secret handed to a layer with escaping rules of its own.
 */
function envFileAt(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'cbox-env');
    file_put_contents($path, $contents);

    return [new EnvFile($path), $path];
}

it('writes a value verbatim, whatever regex metacharacters it carries', function (string $value): void {
    // The key must ALREADY be present: the append path never touched preg_replace, so a
    // test that only appended would have passed against the broken writer.
    [$env, $path] = envFileAt("APP_NAME=Cbox\nCBOX_ID_ISSUER=placeholder\nAPP_DEBUG=false\n");

    expect($env->set('CBOX_ID_ISSUER', $value))->toBeTrue()
        ->and($env->get('CBOX_ID_ISSUER'))->toBe($value);

    // …and the rest of the file is untouched, which is the other half of this class's
    // stated contract: it rewrites one line and keeps everything else byte for byte.
    $written = (string) file_get_contents($path);

    expect($written)->toContain('APP_NAME=Cbox')
        ->and($written)->toContain('APP_DEBUG=false');

    unlink($path);
})->with([
    'a backreference' => 'https://id.acme.com/$1/x',
    'a dollar-brace' => 'https://id.acme.com/${HOME}',
    'a backslash escape' => 'C:\\path\\to\\thing',
    'a doubled backslash' => 'a\\\\b',
    'a base64 crypto key' => 'K5tZ+p/7cQ8mNvR2xY1wL3jH6sD4fG9aB0eI8uO2kM=',
    'an ordinary issuer' => 'https://id.acme.com',
]);

it('appends a value verbatim when the key is absent', function (): void {
    [$env, $path] = envFileAt("APP_NAME=Cbox\n");

    expect($env->set('CBOX_ID_CRYPTO_KEY', 'a$1b\\c'))->toBeTrue()
        ->and($env->get('CBOX_ID_CRYPTO_KEY'))->toBe('a$1b\\c');

    unlink($path);
});

/**
 * A value ending in a backslash must not escape its own closing quote.
 *
 * `escape()` quoted values containing whitespace and escaped the quotes inside them, but
 * not the backslashes — so `a"b\` became `"a\"b\"`, an unterminated string that swallows
 * whatever line follows it in the file.
 */
it('does not let a quoted value escape its own closing quote', function (): void {
    [$env, $path] = envFileAt("APP_NAME=Cbox\nCBOX_ID_ISSUER=placeholder\nAPP_DEBUG=false\n");

    // Whitespace forces the quoted form; the trailing backslash is what escapes the
    // closing quote when only the quotes are escaped.
    $env->set('CBOX_ID_ISSUER', 'a b\\');

    // PARSED WITH A REAL DOTENV READER, not with EnvFile::get(). `get()` matches a line
    // with a regex and never asks whether a quoted string was terminated — so it reports
    // both keys happily over a file that Laravel itself would read as one broken value
    // swallowing the next line. My first version of this test asserted through `get()`
    // and stayed green with the escaping removed; the mutation is what said so.
    $parsed = Dotenv::parse((string) file_get_contents($path));

    expect($parsed)->toHaveKey('APP_DEBUG')
        ->and($parsed['APP_DEBUG'])->toBe('false')
        ->and($parsed['CBOX_ID_ISSUER'])->toBe('a b\\');

    unlink($path);
});
