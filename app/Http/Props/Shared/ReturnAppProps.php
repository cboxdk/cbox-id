<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Invitations\ValueObjects\ReturnApp;

/** An app the invite form can send somebody back to once they accept. */
final readonly class ReturnAppProps implements Prop
{
    /**
     * @param  list<string>  $origins
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public array $origins,
    ) {}

    public static function from(ReturnApp $app): self
    {
        return new self($app->clientId, $app->name, $app->origins);
    }

    /**
     * @return array{clientId: string, name: string, origins: list<string>}
     */
    public function toArray(): array
    {
        return ['clientId' => $this->clientId, 'name' => $this->name, 'origins' => $this->origins];
    }
}
