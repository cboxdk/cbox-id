<?php

declare(strict_types=1);

namespace Cbox\Id\Connectors\ValueObjects;

use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Enums\ConnectorCategory;

/**
 * A catalog entry describing one connector TYPE the platform can speak. It is pure
 * metadata — identity, category, prose, and what the underlying module's PUBLIC
 * contract lets this plugin do — never a live connection or a concrete model. The
 * console renders the catalog from these; {@see ConnectionsOverview}
 * turns the enumerable ones into per-organization {@see ConnectionSummary} rows.
 */
readonly class ConnectorDescriptor
{
    public function __construct(
        public string $key,
        public ConnectorCategory $category,
        public string $name,
        public string $description,
        /**
         * The public module contract this descriptor delegates to (interface name).
         * Documented here so the catalog is honest about what backs each type.
         */
        public string $contract,
        /**
         * Whether the module's PUBLIC contract can list an organization's existing
         * connections. When false the type is catalogued and manageable through the
         * host's own pages, but its live connections cannot be enumerated here (the
         * contract exposes no list) — the overview says so rather than reaching into
         * a concrete model.
         */
        public bool $enumerable,
    ) {}
}
