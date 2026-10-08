<?php

declare(strict_types=1);

/*
 * Describe the schema of the database DB_* points at — every table's columns (type,
 * nullability, default) and indexes (columns, uniqueness) — as stable, sorted JSON, so
 * two databases can be compared with `diff`.
 *
 *     php schema-dump.php > schema.json
 *
 * Used to prove a rollback: the schema after `migrate:rollback` must equal the schema
 * the previous release's own migrations produce. Plain PDO, like row-counts.php.
 */

$driver = getenv('DB_CONNECTION') ?: 'mysql';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
$database = getenv('DB_DATABASE') ?: 'cbox_id';

$pdo = new PDO(
    $driver === 'pgsql' ? "pgsql:host={$host};port={$port};dbname={$database}" : "mysql:host={$host};port={$port};dbname={$database}",
    getenv('DB_USERNAME') ?: 'cbox_id',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$schema = [];

if ($driver === 'pgsql') {
    $columns = $pdo->query(<<<'SQL'
        select table_name, column_name, data_type, character_maximum_length as len, is_nullable, column_default
        from information_schema.columns where table_schema = current_schema()
    SQL)->fetchAll();
    $indexes = $pdo->query(<<<'SQL'
        select t.relname as table_name, i.relname as index_name, ix.indisunique as is_unique,
               array_to_string(array(select a.attname from unnest(ix.indkey) with ordinality k(attnum, ord)
                   join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum order by k.ord), ',') as cols
        from pg_index ix join pg_class t on t.oid = ix.indrelid join pg_class i on i.oid = ix.indexrelid
        join pg_namespace n on n.oid = t.relnamespace where n.nspname = current_schema()
    SQL)->fetchAll();
} else {
    $columns = $pdo->query(<<<'SQL'
        select table_name, column_name, column_type as data_type, null as len, is_nullable, column_default
        from information_schema.columns where table_schema = database()
    SQL)->fetchAll();
    $indexes = $pdo->query(<<<'SQL'
        select table_name, index_name, non_unique = 0 as is_unique,
               group_concat(column_name order by seq_in_index) as cols
        from information_schema.statistics where table_schema = database()
        group by table_name, index_name, non_unique
    SQL)->fetchAll();
}

foreach ($columns as $c) {
    $c = array_change_key_case($c, CASE_LOWER);
    $type = $c['data_type'].($c['len'] !== null ? "({$c['len']})" : '');
    // A sequence default names the sequence, which differs between a fresh table and one
    // rebuilt by a rollback; whether the column auto-increments is what matters.
    $default = $c['column_default'];
    if (is_string($default) && str_starts_with($default, 'nextval(')) {
        $default = 'nextval(…)';
    }
    $schema[$c['table_name']]['columns'][$c['column_name']] = $type.' '.($c['is_nullable'] === 'YES' ? 'null' : 'not null').($default !== null ? ' default '.$default : '');
}

foreach ($indexes as $i) {
    $i = array_change_key_case($i, CASE_LOWER);
    $schema[$i['table_name']]['indexes'][$i['index_name']] = ((bool) $i['is_unique'] ? 'unique ' : '').'('.$i['cols'].')';
}

ksort($schema);
foreach ($schema as &$table) {
    ksort($table['columns']);
    if (isset($table['indexes'])) {
        ksort($table['indexes']);
    }
}

echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
