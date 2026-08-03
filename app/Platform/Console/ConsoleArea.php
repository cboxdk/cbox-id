<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Navigation\ConsoleNavigation;
use App\Providers\ConsoleServiceProvider;

/**
 * An area of the console, named once for both planes.
 *
 * The console has two navigations. The organization plane assembles its rail at runtime
 * from the console-kit registry, keyed on a short string ('overview', 'audit'); the
 * environment plane declares its rail statically in {@see ConsoleNavigation}, keyed on
 * the label a person reads ('Overview', 'Logs'). Nothing connected the two, so a module
 * that registered into 'audit' contributed to one rail and was invisible on the other —
 * which is how six modules ended up on one plane without anyone deciding they should be.
 *
 * This is the mapping, stated once. An area that genuinely exists on one plane only
 * says so by answering null, which is a decision a reader can see rather than an
 * omission nobody wrote down.
 */
enum ConsoleArea: string
{
    case Overview = 'overview';

    case Directory = 'directory';

    case Authentication = 'authentication';

    case Governance = 'governance';

    case Developers = 'developers';

    case Connectors = 'connectors';

    /** Keyed 'audit' in the registry, labelled "Logs" on both rails. */
    case Logs = 'audit';

    case Settings = 'settings';

    case Account = 'account';

    /**
     * The organization rail's label for this area.
     *
     * Identical to what {@see ConsoleServiceProvider} seeds, deliberately: the registry
     * applies a passed label as an override, so a module naming an area differently
     * silently restyles a host area for the whole console. Answering the host's own
     * value makes that impossible rather than merely discouraged.
     */
    public function organizationLabel(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Directory => 'People',
            self::Authentication => 'Sign-in',
            self::Governance => 'Access control',
            self::Developers => 'Developers',
            self::Connectors => 'Connectors',
            self::Logs => 'Logs',
            self::Settings => 'Settings',
            self::Account => 'My account',
        };
    }

    /** The organization rail's icon. Same reasoning as {@see organizationLabel()}. */
    public function organizationIcon(): string
    {
        return match ($this) {
            self::Overview => 'dashboard',
            self::Directory => 'members',
            self::Authentication => 'connections',
            self::Governance => 'shield',
            self::Developers => 'clients',
            self::Connectors => 'connections',
            self::Logs => 'audit',
            self::Settings => 'settings',
            self::Account => 'key',
        };
    }

    /**
     * The organization rail's sort order.
     *
     * AREA ORDERS ARE UNIQUE ACROSS MODULES TOO — the registry sorts on this alone, so a
     * tie resolves by provider boot order and the rail reshuffles when a module is turned
     * on. Holding every area's number in one enum is what makes that checkable.
     */
    public function organizationOrder(): int
    {
        return match ($this) {
            self::Overview => 10,
            self::Directory => 20,
            self::Authentication => 30,
            self::Governance => 40,
            self::Developers => 50,
            self::Connectors => 60,
            self::Logs => 70,
            self::Settings => 80,
            self::Account => 90,
        };
    }

    /**
     * The environment rail's label, or null when this area has no counterpart there.
     *
     * Null is a statement, not a gap. An environment administrator holds the environment
     * from the account layer; their own password, passkeys and sessions live in the
     * workspace console, not in the tenant environment they are administering — so there
     * is no "My account" area here for a personal page to land in.
     */
    public function environmentLabel(): ?string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Directory => 'People',
            self::Authentication => 'Sign-in',
            self::Governance => 'Access control',
            self::Developers => 'Developers',
            self::Connectors => 'Connectors',
            self::Logs => 'Logs',
            self::Settings => 'Settings',
            self::Account => null,
        };
    }

    /**
     * The environment rail's icon, used only when a module's area is not already one of
     * the environment console's own.
     */
    public function environmentIcon(): string
    {
        return match ($this) {
            self::Overview => 'dashboard',
            self::Directory => 'members',
            self::Authentication => 'connections',
            self::Governance => 'shield-check',
            self::Developers => 'clients',
            self::Connectors => 'connections',
            self::Logs => 'audit',
            self::Settings => 'settings',
            self::Account => 'key',
        };
    }

    /**
     * Where a module-introduced area slots into the environment rail — the label it is
     * placed after, so the two rails read in the same order.
     *
     * The organization rail sorts on a number; the environment rail is a written list.
     * Naming the neighbour rather than renumbering the list keeps the existing rail's
     * order exactly as it is, which is the half of the console this task must not move.
     */
    public function environmentAfter(): ?string
    {
        return match ($this) {
            self::Connectors => 'Developers',
            default => null,
        };
    }
}
