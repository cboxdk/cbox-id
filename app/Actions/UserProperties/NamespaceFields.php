<?php

declare(strict_types=1);

namespace App\Actions\UserProperties;

use Cbox\Id\UserProperties\Models\PropertyNamespace;

final class NamespaceFields
{
    /** @return array<string, mixed> */
    public static function present(PropertyNamespace $namespace): array
    {
        return ['name' => $namespace->name, 'readers' => $namespace->readers, 'writers' => $namespace->writers, 'webhooks' => (object) $namespace->webhooks];
    }
}
