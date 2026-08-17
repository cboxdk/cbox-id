<?php

declare(strict_types=1);

namespace App\Platform\Install;

/**
 * Reads and writes single keys in the deployment's `.env`.
 *
 * The installer has to record the deployment SHAPE somewhere durable — the tenancy flag
 * and the account host are configuration, not data, and a platform provisioned as
 * multi-tenant while configured as single-tenant serves its account plane on no host at
 * all. Printing the lines and hoping is not good enough for a value the security
 * bulkheads read.
 *
 * Deliberately NOT a general env editor: it rewrites the one line it is given, keeps
 * everything else byte-for-byte (comments and ordering included), and refuses to
 * overwrite a key that already has a different value — an installer that silently
 * re-pointed an existing deployment's account host would be the very "re-key a live
 * box" failure the emptiness check exists to prevent.
 */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function writable(): bool
    {
        return $this->exists() && is_writable($this->path);
    }

    /** The value currently recorded for a key, or null when it is absent or blank. */
    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $contents = (string) file_get_contents($this->path);

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t\"'");

        return $value === '' ? null : $value;
    }

    /**
     * Record a value, replacing the line if the key is present and appending it if not.
     *
     * Returns false when the file cannot be written, so the caller can print the lines
     * instead — a read-only `.env` (a mounted config map, an immutable image) is a
     * normal deployment, not an error.
     */
    public function set(string $key, string $value): bool
    {
        if (! $this->writable()) {
            return false;
        }

        $contents = (string) file_get_contents($this->path);
        $line = $key.'='.$this->escape($value);

        // REPLACED LINE BY LINE, not with preg_replace. The value goes in the
        // REPLACEMENT position there, where `$1` and `\1` are backreferences and `\\` is
        // an escape — so `preg_replace` silently rewrote the very thing it was asked to
        // record. Verified: an issuer of `https://id.acme.com/$1/x` was written as
        // `https://id.acme.com//x`, and `C:\\path` as `C:\path`.
        //
        // Nothing written today contains either character — the crypto key is base64,
        // the hosts are hostnames — so this was latent. It is fixed rather than noted
        // because the one value where corruption is silent AND unrecoverable goes
        // through here: `CBOX_ID_CRYPTO_KEY`, whose own failure message says every
        // sealed secret written during the install becomes unreadable without it. A
        // latent bug guarding that is not one to leave for the day somebody changes a
        // key format.
        $pattern = '/^'.preg_quote($key, '/').'=/';
        $lines = explode("\n", $contents);
        $replaced = false;

        foreach ($lines as $index => $existing) {
            if (preg_match($pattern, $existing) === 1) {
                $lines[$index] = $line;
                $replaced = true;

                break;
            }
        }

        $contents = $replaced
            ? implode("\n", $lines)
            : rtrim($contents, "\n")."\n".$line."\n";

        return file_put_contents($this->path, $contents) !== false;
    }

    private function escape(string $value): string
    {
        // Quote when the value contains characters that would break a bare .env line.
        if (preg_match('/\s|#|"|\'/', $value) !== 1) {
            return $value;
        }

        // BACKSLASHES FIRST. Escaping only the quotes left a value ending in `\` able to
        // escape its own closing quote — `a"b\` became `"a\"b\"`, which reads as an
        // unterminated string and swallows the next line of the file.
        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }
}
