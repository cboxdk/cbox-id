<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;
use App\Platform\Console\ShellPayload;

/**
 * WHAT THIS PAGE ACTS ON, said above every console page — the environment, the workspace,
 * the organization, or the whole deployment.
 *
 * It exists because the platform root made the question unanswerable by looking. An operator
 * on `cboxid.com/social-sign-in` saw a Social login page and reasonably took it for the
 * platform's own; it configured their workspace's ORGANIZATION in the root environment, which
 * nobody signs in to with it. The topbar's switcher names a place, but a crumb is something
 * you read when you are lost, not before a write. One line above the page, in the console's
 * own nouns, says what a save on this page changes — before anybody saves.
 *
 * `critical` marks the platform root's own environment: how every workspace owner and every
 * operator signs in, so a change there reaches everyone, and the line says so in the
 * colours a warning uses.
 *
 * @see ShellPayload — the only place it is decided.
 */
final readonly class ShellActsOnProps implements Prop
{
    /**
     * @param  'environment'|'workspace'|'organization'|'platform'  $kind
     */
    public function __construct(
        public string $kind,
        public string $label,
        public ?string $detail = null,
        public bool $critical = false,
    ) {}

    /**
     * @return array{kind: string, label: string, detail: string|null, critical: bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'detail' => $this->detail,
            'critical' => $this->critical,
        ];
    }
}
