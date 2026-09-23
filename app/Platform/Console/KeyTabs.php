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
 * console has one Keys page now, with the kind as a tab:
 *
 *  - the WORKSPACE console: management keys for any environment the person may reach, and
 *    the workspace's own API keys;
 *  - the ENVIRONMENT console: this environment's management keys, and its frontend keys.
 *
 * Management keys come first on both, because they are the ones a developer reaches for.
 * A tab the person may not open is not drawn, by the same questions the tab's own page asks.
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
            // means administering this environment.
            $tabs[] = new LinkTabProps(self::MANAGEMENT, 'Management keys', route('environment.keys'), $current === self::MANAGEMENT);
            $tabs[] = new LinkTabProps(self::FRONTEND, 'Frontend keys', route('environment.keys.frontend'), $current === self::FRONTEND);

            return $tabs;
        }

        $can = $this->scope->capabilities();

        if ($can?->canManageEnvironments() === true) {
            $tabs[] = new LinkTabProps(self::MANAGEMENT, 'Management keys', route('keys'), $current === self::MANAGEMENT);
        }

        if ($can?->canManageMembers() === true) {
            $tabs[] = new LinkTabProps(self::WORKSPACE, 'Workspace keys', route('keys.workspace'), $current === self::WORKSPACE);
        }

        return $tabs;
    }
}
