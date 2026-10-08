<?php

declare(strict_types=1);

/*
 * Compare two row-count snapshots (row-counts.php) taken before and after an upgrade.
 *
 *     php compare-counts.php before.json after.json
 *
 * An upgrade may ADD tables and may add rows to some (the migrations table, a backfill
 * into a new table). It must not drop a table that held rows, and it must not lose rows
 * from one — except where a migration is documented to (EXPECTED_CHANGES below), and
 * those are printed so a reader sees them rather than trusting this list.
 *
 * Exits 1 on an unexpected loss.
 */

/**
 * Tables whose count an upgrade from 1.1.x legitimately changes, and why.
 *
 * @var array<string, string>
 */
const EXPECTED_CHANGES = [
    'migrations' => 'one row per migration run',
    'invitation_role_grants' => 'grants for an invitation that is no longer pending are dropped (2026_08_18_000200)',
    'cache' => 'cache entries come and go',
    'cache_locks' => 'cache locks come and go',
    'events' => 'the relay appends while the new code boots and verifies',
    'audit_logs' => 'the verifier itself signs in and writes entries',
    'jobs' => 'queued work from the verifier',
];

[$self, $beforePath, $afterPath] = $argv + [null, null, null];
if ($beforePath === null || $afterPath === null) {
    fwrite(STDERR, "usage: php compare-counts.php before.json after.json\n");
    exit(2);
}

/** @var array<string, int> $before */
$before = json_decode((string) file_get_contents($beforePath), true, flags: JSON_THROW_ON_ERROR);
/** @var array<string, int> $after */
$after = json_decode((string) file_get_contents($afterPath), true, flags: JSON_THROW_ON_ERROR);

$problems = 0;
printf("%-40s %8s %8s  %s\n", 'table', 'before', 'after', '');
foreach ($before as $table => $was) {
    $now = $after[$table] ?? null;
    if ($was === 0 && $now === 0) {
        continue;
    }
    $note = '';
    if ($now === null) {
        $note = $was > 0 ? 'DROPPED WITH ROWS' : 'dropped (empty)';
        $problems += $was > 0 ? 1 : 0;
    } elseif ($now < $was) {
        $note = isset(EXPECTED_CHANGES[$table]) ? 'expected: '.EXPECTED_CHANGES[$table] : 'ROWS LOST';
        $problems += isset(EXPECTED_CHANGES[$table]) ? 0 : 1;
    } elseif ($now > $was) {
        $note = isset(EXPECTED_CHANGES[$table]) ? 'expected: '.EXPECTED_CHANGES[$table] : 'grew';
    }
    printf("%-40s %8d %8s  %s\n", $table, $was, $now === null ? '-' : (string) $now, $note);
}

$new = array_diff_key($after, $before);
if ($new !== []) {
    echo "\nnew tables: ";
    $parts = [];
    foreach ($new as $table => $count) {
        $parts[] = $count > 0 ? "{$table} ({$count} rows)" : $table;
    }
    echo implode(', ', $parts), "\n";
}

echo $problems === 0 ? "\nrow counts: OK\n" : "\nrow counts: {$problems} unexpected loss(es)\n";
exit($problems === 0 ? 0 : 1);
