<?php

declare(strict_types=1);

/*
 * Count the rows of every table in the database DB_* points at, as JSON.
 *
 *     php row-counts.php > counts.json
 *
 * Plain PDO on purpose: it runs between two releases, so it must not boot either of them.
 */

$driver = getenv('DB_CONNECTION') ?: 'mysql';
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
$database = getenv('DB_DATABASE') ?: 'cbox_id';

$pdo = new PDO(
    $driver === 'pgsql' ? "pgsql:host={$host};port={$port};dbname={$database}" : "mysql:host={$host};port={$port};dbname={$database}",
    getenv('DB_USERNAME') ?: 'cbox_id',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$tables = $driver === 'pgsql'
    ? $pdo->query('select tablename from pg_tables where schemaname = current_schema() order by 1')->fetchAll(PDO::FETCH_COLUMN)
    : $pdo->query('select table_name from information_schema.tables where table_schema = database() and table_type = \'BASE TABLE\' order by 1')->fetchAll(PDO::FETCH_COLUMN);

$counts = [];
foreach ($tables as $table) {
    $quoted = $driver === 'pgsql' ? '"'.$table.'"' : '`'.$table.'`';
    $counts[$table] = (int) $pdo->query("select count(*) from {$quoted}")->fetchColumn();
}

echo json_encode($counts, JSON_PRETTY_PRINT).PHP_EOL;
