<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\OperatorPrincipal;
use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What every platform action needs to know about who is acting: the OPERATOR record behind
 * the person, whichever door they came through. A helper, not an action.
 *
 * The platform services record their own audit entries against an operator id —
 * {@see Organizations::suspend()} and the operator roster take it as `$actorId` — so that
 * id is the whole of the actor, and it is the same person from the console and from a
 * token they delegated: the trail reads the same either way.
 */
final class AsOperator
{
    /**
     * The acting operator's id, or a refusal: only a platform operator acts here.
     *
     * @throws AuthorizationException
     */
    public static function id(Principal $principal): string
    {
        $id = match (true) {
            $principal instanceof OperatorPrincipal => $principal->operatorId(),
            $principal instanceof ConsoleSessionPrincipal => $principal->scope()->operator()?->id,
            default => null,
        };

        if (! is_string($id) || $id === '') {
            throw new AuthorizationException('Only a platform operator can do this.');
        }

        return $id;
    }

    /**
     * An environment on this deployment, by id, or 404 — looked up OUTSIDE any ambient
     * scope, because the operator acts across every environment and an environment is the
     * tenancy root, owned by none.
     *
     * @throws ActionRefused
     */
    public static function environment(string $environmentId): Environment
    {
        return app(EnvironmentContext::class)->withoutScope(
            static fn (): ?Environment => Environment::query()->find($environmentId),
        ) ?? throw ActionRefused::notFound('environment');
    }
}
