<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Controllers\DeviceApprovalController;
use App\Http\Controllers\OAuthConsentController;
use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\AppManagementScopes;
use App\Platform\Actions\Danger;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\ScopeCatalog;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Illuminate\Support\Facades\Lang;

/**
 * What a person is agreeing to, one row per scope — the list the consent screen
 * ({@see OAuthConsentController}) and the device page ({@see DeviceApprovalController}) show.
 *
 * Two kinds of scope reach those screens now, and they are not the same weight:
 *
 *  - SIGN-IN scopes say what an app learns about the person (`email`, `groups`). Their
 *    phrases come from {@see ScopeCatalog}, in the visitor's language.
 *  - MANAGEMENT scopes say what an agent may DO as the person: on this environment's
 *    management plane (`webhooks:write`), on their own account (`account:*`), and — signing
 *    in at the platform root — on their workspace (`team:write`) or, for an operator, the
 *    deployment (`operator:*`). Their label is the one each plane's catalogue gives
 *    ({@see AppManagementScopes::label()} and its siblings) — the management planes are
 *    administered in English, like the console — and a scope any CRITICAL action needs is
 *    flagged, read from the action registry rather than kept as a second list: minting a
 *    credential or changing how people sign in should not look like reading a name.
 *
 * A scope that is neither is shown as its own key.
 */
final readonly class ConsentScopes
{
    public function __construct(
        private ScopeCatalog $catalog,
        private ManagementScopes $management,
        private ActionRegistry $actions,
    ) {}

    /**
     * @param  list<string>  $scopes
     * @return list<array{scope: string, label: string, management: bool, critical: bool}>
     */
    public function rows(array $scopes): array
    {
        $labels = $this->catalog->consentLabels();
        $critical = $this->criticalScopes();

        return array_map(function (string $scope) use ($labels, $critical): array {
            $label = isset($labels[$scope]) ? null : $this->managementLabel($scope);
            $management = $label !== null;

            return [
                'scope' => $scope,
                'label' => $label ?? $this->signInLabel($scope, $labels),
                'management' => $management,
                'critical' => $management && isset($critical[$scope]),
            ];
        }, $scopes);
    }

    /**
     * A management scope's label from the plane that defines it — the environment's first,
     * because a scope two planes share (`keys:write`) reads the way the environment's key
     * form puts it — or null when no plane defines it.
     */
    private function managementLabel(string $scope): ?string
    {
        return match (true) {
            $this->management->knows($scope) => AppManagementScopes::label($scope),
            AccountScopes::knows($scope) => AccountScopes::label($scope),
            WorkspaceScopes::knows($scope) => WorkspaceScopes::label($scope),
            PlatformScopes::knows($scope) => PlatformScopes::label($scope),
            default => null,
        };
    }

    /**
     * A built-in sign-in scope in the visitor's language; a custom one as its own key.
     *
     * The catalog decides WHICH scopes have a phrase, and its English is the fallback for one
     * added there before the language files caught up — so a new built-in scope is never
     * shown to a person as its bare key just because nobody translated it yet.
     *
     * @param  array<string, string>  $labels
     */
    private function signInLabel(string $scope, array $labels): string
    {
        if (! isset($labels[$scope])) {
            return $scope;
        }

        $key = 'oauth.consent.scopes.'.$scope;

        return Lang::has($key) ? __($key) : $labels[$scope];
    }

    /**
     * The management scopes some critical action needs, on any plane.
     *
     * @return array<string, true>
     */
    private function criticalScopes(): array
    {
        $critical = [];

        foreach ($this->actions->all() as $action) {
            if ($action->danger === Danger::Critical) {
                $critical[$action->scope] = true;
            }
        }

        return $critical;
    }
}
