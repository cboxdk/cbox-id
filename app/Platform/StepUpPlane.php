<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\ConsoleSignIn\ConsoleSignIn;
use App\Platform\Operators\OperatorAssurance;

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

    /**
     * The operator plane, where the proof is a passkey rather than a password
     * ({@see OperatorAssurance}). Asked for only while
     * `cbox-id.operators.phishing_resistant` is on.
     */
    case Operator = 'operator.sudo';

    /** Whether this plane's window is open right now. */
    public function confirmed(): bool
    {
        return match ($this) {
            self::Organization => app(Sudo::class)->confirmed(),
            self::Environment => app(EnvironmentSudo::class)->confirmed(),
            self::Operator => app(OperatorAssurance::class)->fresh(),
        };
    }

    /**
     * The full-page "Confirm it's you" screen — the fallback for a request with no script.
     *
     * In login-host mode the console has no such screen: the step-up is a trip to the
     * login host through the console's door ({@see redirectUrl()}).
     */
    public function pageUrl(): string
    {
        return $this->redirectUrl() ?? route($this->value);
    }

    /**
     * Where the browser is sent to step up at the login host, or null where the console
     * confirms on its own origin (single-host mode). `$popup` is the in-place dialog's
     * window, which is answered with a page that closes itself.
     */
    public function redirectUrl(bool $popup = false): ?string
    {
        $door = app(ConsoleSignIn::class);

        return $door->active()
            ? $door->startUrl(['step_up' => $this->label(), 'popup' => $popup ? 1 : null])
            : null;
    }

    /**
     * Where a confirmation is POSTed. The same route answers the full-page form and the
     * in-place dialog; the dialog asks for JSON.
     */
    public function confirmUrl(): string
    {
        // Nothing is posted to the console in login-host mode; the name is kept for a
        // client that reads it, and it leads to the same door.
        return $this->redirectUrl() ?? route($this->value.'.confirm');
    }

    /** What the client is told, so it can say which door it is asking at. */
    public function label(): string
    {
        return match ($this) {
            self::Organization => 'organization',
            self::Environment => 'environment',
            self::Operator => 'operator',
        };
    }

    /**
     * Where the browser fetches the WebAuthn challenge, for a plane confirmed with a
     * passkey; null for a plane confirmed with a password.
     */
    public function passkeyOptionsUrl(): ?string
    {
        return $this === self::Operator ? route('operator.sudo.options') : null;
    }
}
