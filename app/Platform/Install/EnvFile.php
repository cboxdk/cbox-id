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

        $contents = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1
            ? (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents)
            : rtrim($contents, "\n")."\n".$line."\n";

        return file_put_contents($this->path, $contents) !== false;
    }

    private function escape(string $value): string
    {
        // Quote when the value contains characters that would break a bare .env line.
        return preg_match('/\s|#|"|\'/', $value) === 1 ? '"'.str_replace('"', '\"', $value).'"' : $value;
    }
}
