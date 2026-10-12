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
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Cbox\Id\Platform\PlatformRoot;

/**
 * A root-host token whose person RUNS THE DEPLOYMENT: an active platform operator, decided
 * the way the console decides it ({@see ConsoleScope::operator()} — the operator record
 * behind the subject, which the framework lookup refuses once suspended), asked when the
 * token is presented ({@see RootDelegatedAccess}).
 *
 * Everything a {@see RootPersonPrincipal} may do, plus the platform plane within the
 * `operator:*` scopes the token carries ({@see PlatformScopes}). Every platform action is
 * critical, so every one waits for the operator's own approval on their device.
 *
 * PLUS THE PLATFORM ROOT'S OWN ENVIRONMENT, as one more environment to act in — named the
 * way any other is, by id or slug (`Cbox-Environment: platform`, the MCP `environment`
 * argument). It is the environment every workspace owner and operator signs in to, and its
 * console is the operators' ({@see EnvironmentMemberPrincipal::forPlatformRoot()}), so its
 * every environment action is theirs to run with the token's scopes, exactly as that console
 * runs it.
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

    /**
     * Any environment action the operator's token carries the scope for: there is always one
     * environment it may run in — the platform root's.
     */
    public function reachesEnvironmentAction(ActionDefinition $action): bool
    {
        return parent::reachesEnvironmentAction($action)
            || ($action->plane === ActionPlane::Environment
                && EnvironmentMemberPrincipal::consoleRuns($action)
                && app(ManagementScopes::class)->knows($action->scope)
                && $this->signIn->grants($action->scope));
    }

    /**
     * The platform root's own environment, when that is the one named — else the workspace's
     * environments, as for any person.
     */
    public function inEnvironment(string $reference): EnvironmentMemberPrincipal
    {
        $root = $this->platformRoot($reference);

        return $root !== null
            ? EnvironmentMemberPrincipal::forPlatformRoot($this->signIn, $root, $this->workspace)
            : parent::inEnvironment($reference);
    }

    /**
     * The workspace's environments, and the platform root's after them.
     *
     * @return list<Environment>
     */
    public function environments(): array
    {
        $root = app(PlatformRoot::class)->model();

        return $root === null ? parent::environments() : [...parent::environments(), $root];
    }

    /** The platform root's environment, if $reference names it by id or slug. */
    private function platformRoot(string $reference): ?Environment
    {
        $reference = trim($reference);
        $root = app(PlatformRoot::class)->model();

        if ($reference === '' || $root === null) {
            return null;
        }

        return $reference === $root->id || $reference === $root->slug ? $root : null;
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
