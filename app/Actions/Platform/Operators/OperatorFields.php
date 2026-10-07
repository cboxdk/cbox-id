<?php

declare(strict_types=1);

namespace App\Actions\Platform\Operators;

use App\Http\Resources\Environment\Timestamp;
use Cbox\Id\Platform\Models\PlatformOperator;

/**
 * A platform operator as the operator API returns it — `PlatformOperator` in the spec. A
 * helper, not an action. Never the password, nor anything derived from it.
 */
final class OperatorFields
{
    /**
     * @return array<string, mixed>
     */
    public static function present(PlatformOperator $operator): array
    {
        return [
            'id' => $operator->id,
            'email' => $operator->email,
            'name' => $operator->name,
            'status' => $operator->isActive() ? 'active' : 'suspended',
            'created_at' => Timestamp::of($operator->getAttribute('created_at')),
        ];
    }
}
