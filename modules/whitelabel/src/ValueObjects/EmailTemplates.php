<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\ValueObjects;

/**
 * A tenant's overridden transactional email bodies, keyed by template name
 * (`welcome`, …). Absent means "use the platform default", which is why every
 * accessor returns null rather than an empty string — a tenant who deliberately
 * blanks a template is saying something different from one who never set it.
 *
 * Stored in a `json` column: an open, small map at a real serialization boundary.
 * The PHP side stays typed so a template body is never a `mixed` pulled out of an
 * array and rendered.
 */
readonly class EmailTemplates
{
    /** @param array<string, string> $templates name => body */
    private function __construct(public array $templates) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $templates = [];

        foreach ($raw as $name => $body) {
            if (is_string($name) && is_string($body)) {
                $templates[$name] = $body;
            }
        }

        return new self($templates);
    }

    public function get(string $name): ?string
    {
        return $this->templates[$name] ?? null;
    }

    /** A copy with one template set, or removed when the body is blank. */
    public function with(string $name, string $body): self
    {
        $templates = $this->templates;

        if (trim($body) === '') {
            unset($templates[$name]);
        } else {
            $templates[$name] = $body;
        }

        return new self($templates);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->templates;
    }
}
