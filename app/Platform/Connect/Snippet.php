<?php

declare(strict_types=1);

namespace App\Platform\Connect;

/**
 * One "wire it up" example on an app's page — the code for one SDK, already filled in
 * with this app's real issuer, client id and scopes.
 *
 * A value object rather than a bare array because the page renders these as tabs, and a
 * tab needs three things that must not drift apart: a stable id for the tab control, a
 * label a person reads, and the code itself. `[$label, $code]` at the call site loses the
 * id the moment somebody adds a fourth SDK.
 */
readonly class Snippet
{
    /**
     * @param  string  $id  stable, used as the tab's value — never shown
     * @param  string  $label  what the tab says
     * @param  string  $code  ready to paste, with this app's own values in it
     * @param  string|null  $install  the one-line install command, when there is one
     * @param  string|null  $docs  where the full guide lives, when one exists
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $code,
        public ?string $install = null,
        public ?string $docs = null,
    ) {}
}
