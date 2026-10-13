<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The two step-ups the console can ask for, and everything that differs between them.
 *
 * TWO CASES, NEVER ONE KEY. A confirmation on the organization plane ({@see Sudo}) must
 * never satisfy the environment plane ({@see EnvironmentSudo}) or the reverse: an
 * environment administrator acts on every organization in the environment and a tenant
 * administrator acts on one, so a step-up bought in the cheaper place must not spend in
 * the dearer. Each case owns its own session prefix, its own screen and its own confirm
 * endpoint, and nothing here lets one case reach the other's.
 *
 * The backing value is the prefix the plane already owns in the session — `sudo.intended`,
 * `environment.sudo.reason` — and the name of its full-page step-up route, so the three
 * cannot be spelled apart from one another.
 */
enum StepUpPlane: string
{
    case Organization = 'sudo';
    case Environment = 'environment.sudo';

    /** Whether this plane's window is open right now. */
    public function confirmed(): bool
    {
        return match ($this) {
            self::Organization => app(Sudo::class)->confirmed(),
            self::Environment => app(EnvironmentSudo::class)->confirmed(),
        };
    }

    /** The full-page "Confirm it's you" screen — the fallback for a request with no script. */
    public function pageUrl(): string
    {
        return route($this->value);
    }

    /**
     * Where a confirmation is POSTed. The same route answers the full-page form and the
     * in-place dialog; the dialog asks for JSON.
     */
    public function confirmUrl(): string
    {
        return route($this->value.'.confirm');
    }

    /** What the client is told, so it can say which door it is asking at. */
    public function label(): string
    {
        return match ($this) {
            self::Organization => 'organization',
            self::Environment => 'environment',
        };
    }
}
