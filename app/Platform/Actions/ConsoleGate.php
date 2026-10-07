<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\OrganizationCapabilities;

/**
 * Which of the console's gates a person must pass to run an action from the console — the
 * same {@see ConsoleScope} assertion the page used before the action existed.
 *
 * The WORKSPACE gates are capabilities of the workspace being administered
 * ({@see OrganizationCapabilities}), and they are read by both doors: a person in the
 * workspace console must hold the capability, and so must a workspace key's role
 * ({@see WorkspaceKeyPrincipal}). One statement of who may do it, rather than a console
 * rule and an API rule that agree today.
 */
enum ConsoleGate
{
    /** The environment console only: the thing belongs to the environment. */
    case EnvironmentAdmin;

    /** Any console where the person administers what they are looking at. */
    case Administer;

    /** Anybody on the workspace's team, whatever their role: reads of the workspace itself. */
    case WorkspaceMember;

    /** Create and administer projects, environments and their keys. */
    case ManageEnvironments;

    /** Read the team roster, which is PII. */
    case ReadMembers;

    /** Invite, re-role and remove members; mint workspace keys; change workspace settings. */
    case ManageMembers;

    /** Whether this gate is a workspace capability — the only gates a workspace action may name. */
    public function isWorkspace(): bool
    {
        return $this->capability() !== false;
    }

    /**
     * The capability this gate asks of the workspace ({@see WorkspaceScopes::holds()}):
     * null for "any member", false for a gate that is not a workspace capability at all.
     */
    public function capability(): string|false|null
    {
        return match ($this) {
            self::EnvironmentAdmin, self::Administer => false,
            self::WorkspaceMember => null,
            self::ManageEnvironments => 'manage-environments',
            self::ReadMembers => 'read-members',
            self::ManageMembers => 'manage-members',
        };
    }
}
