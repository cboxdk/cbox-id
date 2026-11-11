<?php

declare(strict_types=1);

namespace App\Platform\Realm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the database actually has, read from the database: every table, its columns and
 * its primary key. The flip decides per table from a written list ({@see FlipTables}),
 * and this is what that list is checked against, so a table added after the list was
 * written is found rather than missed.
 */
final class FlipSchema
{
    /** @var array<string, array<string, array{type: string, nullable: bool}>> */
    private array $columns = [];

    /** @var list<string>|null */
    private ?array $tables = null;

    /** @var array<string, list<string>> */
    private array $keys = [];

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $schema = DB::connection()->getDriverName() === 'pgsql' ? DB::scalar('select current_schema()') : null;
        $names = [];

        foreach (Schema::getTables() as $table) {
            $in = is_array($table) ? ($table['schema'] ?? null) : null;
            $name = is_array($table) ? ($table['name'] ?? null) : null;

            if (! is_string($name) || ($schema !== null && $in !== null && $in !== $schema)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $this->tables = array_values(array_unique($names));
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /** @return array<string, array{type: string, nullable: bool}> */
    public function columns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        $columns = [];

        foreach (Schema::getColumns($table) as $column) {
            if (! is_array($column) || ! is_string($column['name'] ?? null)) {
                continue;
            }

            $type = $column['type_name'] ?? null;
            $columns[$column['name']] = ['type' => is_string($type) ? strtolower($type) : '', 'nullable' => (bool) ($column['nullable'] ?? false)];
        }

        return $this->columns[$table] = $columns;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->has($table) && isset($this->columns($table)[$column]);
    }

    public function nullable(string $table, string $column): bool
    {
        return $this->columns($table)[$column]['nullable'] ?? false;
    }

    /** The column that says which environment a row belongs to, where the table has one. */
    public function environmentColumn(string $table): ?string
    {
        if ($table === 'environments' || str_starts_with($table, 'realm_flip')) {
            return null;
        }

        foreach (['environment_id', 'environment_key'] as $candidate) {
            if ($this->hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function primaryKey(string $table): array
    {
        if (isset($this->keys[$table])) {
            return $this->keys[$table];
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (is_array($index) && ($index['primary'] ?? false) === true && is_array($index['columns'] ?? null)) {
                return $this->keys[$table] = array_values(array_filter($index['columns'], 'is_string'));
            }
        }

        return $this->keys[$table] = [];
    }

    /**
     * Columns that can hold an id: short strings compared exactly, and long text or JSON
     * searched for an id anywhere inside.
     *
     * @return array{exact: list<string>, inside: list<string>}
     */
    public function searchable(string $table): array
    {
        $exact = [];
        $inside = [];

        foreach ($this->columns($table) as $name => $column) {
            $type = $column['type'];

            if (str_contains($type, 'json') || $type === 'text' || str_contains($type, 'clob') || $type === 'longtext' || $type === 'mediumtext') {
                $inside[] = $name;
            } elseif (str_contains($type, 'char') || $type === 'string' || $type === 'citext') {
                $exact[] = $name;
            }
        }

        return ['exact' => $exact, 'inside' => $inside];
    }
}
