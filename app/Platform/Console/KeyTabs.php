<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Shared\LinkTabProps;

/**
 * THE KEYS PAGE'S TABS — which kinds of key this console issues, and which of them this
 * person may see.
 *
 * Seven kinds of credential were spread over four pages under three names ("API keys",
 * "Environment keys", "Frontend keys", and the "API keys" half of "Apps & API keys"). Each
 * console has one API keys page now, with the kind as a tab, named from {@see Vocabulary}:
 *
 *  - the WORKSPACE console: Secret keys (management keys) for any environment the person
 *    may reach, and Workspace keys;
 *  - the ENVIRONMENT console: Secret keys and Publishable keys (frontend keys). Its secret
 *    keys are listed as the agents that hold them, so that tab leads to AI agents › Agents
 *    rather than drawing the same list twice.
 *
 * Secret keys come first on both, because they are the ones a developer reaches for. A
 * tab the person may not open is not drawn, by the same questions the tab's own page asks.
 * Member API keys are an organization's page of their own (Members › Member API keys),
 * because they are the organization's people's rather than the workspace's.
 */
final readonly class KeyTabs
{
    public const MANAGEMENT = 'management';

    public const WORKSPACE = 'workspace';

    public const FRONTEND = 'frontend';

    public function __construct(private ConsoleScope $scope) {}

    /**
     * @return list<LinkTabProps>
     */
    public function for(string $current): array
    {
        $tabs = [];

        if ($this->scope->plane() === ConsolePlane::Environment) {
            // The environment console's gate IS the authority for both: reaching it at all
            // means administering this environment. Its management keys are listed as the
            // agents that hold them, so the tab leads to AI agents › Agents.
            $tabs[] = new LinkTabProps(self::MANAGEMENT, Vocabulary::SECRET_KEYS, route('environment.agents'), $current === self::MANAGEMENT);
            $tabs[] = new LinkTabProps(self::FRONTEND, Vocabulary::PUBLISHABLE_KEYS, route('environment.keys.frontend'), $current === self::FRONTEND);

            return $tabs;
        }

        $can = $this->scope->capabilities();

        if ($can?->canManageEnvironments() === true) {
            $tabs[] = new LinkTabProps(self::MANAGEMENT, Vocabulary::SECRET_KEYS, route('keys'), $current === self::MANAGEMENT);
        }

        if ($can?->canManageMembers() === true) {
            $tabs[] = new LinkTabProps(self::WORKSPACE, Vocabulary::WORKSPACE_KEYS, route('keys.workspace'), $current === self::WORKSPACE);
        }

        return $tabs;
    }
}
