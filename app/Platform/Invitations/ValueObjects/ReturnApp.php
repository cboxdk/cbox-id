<?php

declare(strict_types=1);

namespace App\Platform\Invitations\ValueObjects;

/** An app an invitation can send people back to, and the origins it may send them to. */
final readonly class ReturnApp
{
    /**
     * @param  list<string>  $origins  `https://app.example` — where a return address may point
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public array $origins,
    ) {}
}
