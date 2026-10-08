<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Console\ConsoleScope;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OAuth\ValueObjects\RootSignIn;
use App\Platform\OAuth\ValueObjects\RootWorkspace;

/**
 * A root-host token whose person RUNS THE DEPLOYMENT: an active platform operator, decided
 * the way the console decides it ({@see ConsoleScope::operator()} — the operator record
 * behind the subject, which the framework lookup refuses once suspended), asked when the
 * token is presented ({@see RootDelegatedAccess}).
 *
 * Everything a {@see RootPersonPrincipal} may do, plus the platform plane within the
 * `operator:*` scopes the token carries ({@see PlatformScopes}). Every platform action is
 * critical, so every one waits for the operator's own approval on their device.
 */
final readonly class RootOperatorPrincipal extends RootPersonPrincipal implements OperatorPrincipal
{
    public function __construct(
        RootSignIn $signIn,
        ?RootWorkspace $workspace,
        private string $operatorId,
    ) {
        parent::__construct($signIn, $workspace);
    }

    public function authorize(ActionDefinition $action): void
    {
        if ($action->plane === ActionPlane::Platform) {
            $this->assertGranted(PlatformScopes::knows($action->scope), $action->scope);

            return;
        }

        parent::authorize($action);
    }

    public function operatorId(): string
    {
        return $this->operatorId;
    }

    public function isOperator(): bool
    {
        return true;
    }
}
