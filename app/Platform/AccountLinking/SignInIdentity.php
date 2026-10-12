<?php

declare(strict_types=1);

namespace App\Platform\AccountLinking;

use Carbon\CarbonInterface;

/**
 * One linked sign-in identity, as My account shows it and the disconnect action judges it.
 *
 * `providerKey` is the catalogue key (`google`, `github`) — which is also the mark the page
 * draws — or `sso` for an organization's own single sign-on. `email` is the address the
 * provider gave when the identity was linked, shown so a person with two Google accounts
 * can tell which one this is; it is never what the identity is matched on.
 */
final readonly class SignInIdentity
{
    public function __construct(
        public string $id,
        public string $providerKey,
        public string $label,
        public ?string $email,
        public ?CarbonInterface $linkedAt,
        public ?CarbonInterface $lastUsedAt,
        public bool $removable,
    ) {}

    /**
     * The REST/MCP shape, and the page's: ISO-8601 dates, null when unknown.
     *
     * @return array{id: string, provider: string, label: string, email: string|null, linked_at: string|null, last_used_at: string|null, removable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->providerKey,
            'label' => $this->label,
            'email' => $this->email,
            'linked_at' => $this->linkedAt?->toIso8601String(),
            'last_used_at' => $this->lastUsedAt?->toIso8601String(),
            'removable' => $this->removable,
        ];
    }
}
