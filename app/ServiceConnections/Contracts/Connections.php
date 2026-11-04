<?php

declare(strict_types=1);

namespace App\ServiceConnections\Contracts;

use App\ServiceConnections\ServiceConnection;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;

interface Connections
{
    public function inspect(Introspection $caller, string $id): ?ServiceConnection;

    public function connect(Introspection $caller, string $service): ServiceConnection;

    public function revoke(Introspection $caller, string $id): void;

    public function lease(Introspection $caller, string $id): IssuedToken;

    /** @return list<ServiceConnection> */
    public function listing(Introspection $caller): array;
}
