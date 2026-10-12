<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\ValueObjects;

use App\Platform\Help\HelpTopic;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Enums\ConnectorCategory;

/**
 * One connector TYPE in the catalog — what it is, and where in the console it is SET UP,
 * MANAGED and OPENED.
 *
 * It used to carry a contract name and an "enumerable" flag and nothing that led anywhere,
 * so the catalog page could describe a type but not offer a single next step: no setup link,
 * no list link, and for Directory Sync not even a count. A catalog an administrator lands on
 * has to let them do the next thing, so a descriptor now names the console's own pages for
 * its type — by their ORGANIZATION-PLANE route names, which the controller resolves for the
 * plane it is on — and the hub links into them. Every write still happens on those pages,
 * through their actions; nothing here creates or changes a connection.
 *
 * Pure metadata: {@see ConnectionsOverview} turns the live rows of each type into
 * {@see ConnectionSummary} values.
 */
readonly class ConnectorDescriptor
{
    /** The type's own list may show rows it cannot open — see {@see self::$opens}. */
    public const string OPENS_ANY = 'any';

    /** On the organization plane, only the organization's own rows open. */
    public const string OPENS_OWN = 'own';

    /** Each plane opens only what it owns: the environment's rows there, the organization's here. */
    public const string OPENS_PLANE = 'plane';

    /**
     * @param  array<string, string>  $options  key => label: the kinds this type can be set up
     *                                          as (a provider, an HR system, a destination), each
     *                                          a link into the setup form with it chosen
     */
    public function __construct(
        public string $key,
        public ConnectorCategory $category,
        public string $name,
        /** One line: what it is, and when you would want it. */
        public string $summary,
        /** The section of the catalog it is filed under. */
        public string $group,
        /** The type's list page (organization-plane route name). */
        public string $indexRoute,
        /** Where a new one is started — a create form, or the page whose form adds one. */
        public string $createRoute,
        /** One row's own page, taking the row's id; null where rows are edited on the list. */
        public ?string $showRoute,
        /** Where the guide is: a help topic with a guide, or a docs path. */
        public ?HelpTopic $helpTopic = null,
        public ?string $docsPath = null,
        /** The query parameter the setup form reads to preselect one of `$options`. */
        public ?string $optionParameter = null,
        public array $options = [],
        /** Whether the list narrows by `?organization=` — so "Manage" can carry the filter. */
        public bool $organizationFilter = false,
        /** On a list without row pages, the parameter that opens one row in place (`?edit=`). */
        public ?string $rowParameter = null,
        /**
         * Which rows the type's own row page will open. A row page that refuses a row it is
         * offered is a dead link, so a row it would refuse is listed without one.
         */
        public string $opens = self::OPENS_ANY,
    ) {}
}
