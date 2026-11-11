<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Realm\FlipOptions;
use App\Platform\Realm\FlipReport;
use App\Platform\Realm\FlipTables;
use App\Platform\Realm\RealmFlip;
use Illuminate\Console\Command;

/**
 * Make the `cbox` environment the platform root and retire the old one: the one-time data
 * migration behind the single realm. See {@see RealmFlip} for what it does and why, and
 * docs/operations/realm-flip.md for the cutover and the rehearsal.
 *
 * A DRY RUN IS THE DEFAULT, and it is the real thing rolled back: every step runs inside
 * one transaction that is then undone, so the plan and the before/after inventory are
 * what `--execute` will do to this database, not an estimate.
 *
 * `--execute` needs `--confirm=<id of the environment being retired>`: the id is printed
 * by the dry run, so nobody executes a flip they have not read the plan for, and a command
 * line copied to the wrong deployment names an environment that is not there.
 *
 * Safe to run again. A flipped deployment says so and changes nothing.
 */
final class RealmFlipCommand extends Command
{
    protected $signature = 'cbox-id:realm:flip
        {--dry-run : Print the plan and the before/after inventory and change nothing (the default)}
        {--execute : Do it, in one transaction}
        {--confirm= : With --execute: the id of the root environment that is being retired}
        {--verify : Check the post-conditions of an executed flip}
        {--create-missing : Move unlinked root organizations, and root people with no realm account, into the realm with the ids they have}
        {--role-conflict=highest : Where a person has a role on both sides: highest, root or realm}
        {--domain= : The new root\'s domain: a host, or `none`. Default: the configured login host, else the old root\'s domain}
        {--carry-client=* : A root platform client id to carry even though the realm has a client of the same name}
        {--keep-root-audit : Leave the old root\'s audit rows in place (for audit tables that refuse DELETE); they are archived either way}
        {--allow-pending-jobs : Go ahead although jobs are waiting on a queue that cannot be searched}
        {--tables : Print the decision for every table, not only the ones with rows}';

    protected $description = 'Make the cbox environment the platform root and retire the old root (dry run by default)';

    public function handle(RealmFlip $flip): int
    {
        if ($this->option('verify') === true) {
            return $this->verified($flip->verify());
        }

        $execute = $this->option('execute') === true;

        if ($execute && $this->option('dry-run') === true) {
            $this->error('Pass --dry-run or --execute, not both.');

            return self::INVALID;
        }

        $role = $this->option('role-conflict');

        if (! in_array($role, [FlipOptions::ROLE_HIGHEST, FlipOptions::ROLE_ROOT, FlipOptions::ROLE_REALM], true)) {
            $this->error('--role-conflict is one of: highest, root, realm.');

            return self::INVALID;
        }

        $domain = $this->option('domain');
        $carry = $this->option('carry-client');
        $options = new FlipOptions(
            createMissing: $this->option('create-missing') === true,
            roleConflict: $role,
            keepRootAudit: $this->option('keep-root-audit') === true,
            domain: is_string($domain) && $domain !== '' ? $domain : null,
            allowPendingJobs: $this->option('allow-pending-jobs') === true,
            carryClients: array_values(array_filter(is_array($carry) ? $carry : [], 'is_string')),
        );

        if (! $execute) {
            $report = $flip->run($options, commit: false);
            $this->plan($report);

            if ($report->alreadyFlipped) {
                return self::SUCCESS;
            }

            if ($report->blocked()) {
                $this->error('BLOCKED. Nothing was changed, and --execute would refuse.');

                return self::FAILURE;
            }

            $this->info('DRY RUN: every step above ran and was rolled back. Nothing was changed.');
            $this->line('To execute: php artisan cbox-id:realm:flip --execute --confirm='.$report->rootId.$this->repeatOptions());

            return self::SUCCESS;
        }

        // The id to confirm comes from a dry run of the same database: find it without
        // changing anything, and refuse before the transaction that would.
        $preview = $flip->run($options, commit: false);

        if ($preview->alreadyFlipped) {
            $this->plan($preview);

            return self::SUCCESS;
        }

        if ($preview->blocked()) {
            $this->plan($preview);
            $this->error('BLOCKED. Nothing was changed.');

            return self::FAILURE;
        }

        if ($this->option('confirm') !== $preview->rootId) {
            $this->error('--execute needs --confirm='.$preview->rootId.' (the id of the root environment being retired). Read the dry run first.');

            return self::FAILURE;
        }

        $report = $flip->run($options, commit: true);
        $this->plan($report);

        if (! $report->committed) {
            $this->error('NOT EXECUTED. The transaction was rolled back; nothing was changed.');

            return self::FAILURE;
        }

        $this->info('EXECUTED. '.$report->rootId.' is retired; '.$report->realmId.' is the platform root.');
        $this->line('Now: php artisan cbox-id:realm:flip --verify');

        return self::SUCCESS;
    }

    private function verified(FlipReport $report): int
    {
        foreach ($report->facts as $fact) {
            $this->line($fact);
        }

        $this->checks($report);
        $this->inventory('Inventory now', $report->after);

        if (! $report->passed()) {
            $this->error('VERIFY FAILED: '.count($report->failedChecks()).' post-condition(s) do not hold.');

            return self::FAILURE;
        }

        $this->info('VERIFIED: every post-condition holds.');

        return self::SUCCESS;
    }

    private function plan(FlipReport $report): void
    {
        $this->section('Realm flip');

        foreach ($report->facts as $fact) {
            $this->line('  '.$fact);
        }

        if ($report->alreadyFlipped) {
            return;
        }

        if ($report->blockers !== []) {
            $this->section('Blockers ('.count($report->blockers).')');

            foreach ($report->blockers as $blocker) {
                $this->line('  <fg=red>✗</> '.$blocker);
            }
        }

        if ($report->warnings !== []) {
            $this->section('Read before executing ('.count($report->warnings).')');

            foreach ($report->warnings as $warning) {
                $this->line('  <fg=yellow>!</> '.$warning);
            }
        }

        foreach ($report->ids as $kind => $map) {
            if ($map === []) {
                continue;
            }

            $this->section("Id map: {$kind}");
            $this->table(['old id', 'new id', ''], array_map(
                static fn (string $old, ?string $new): array => [$old, $new ?? '—', $new === null ? 'dropped' : ($new === $old ? 'moved, id kept' : 'merged')],
                array_keys($map),
                array_values($map),
            ));
        }

        $this->section('Tables');
        $rows = [];

        foreach ($report->tables as $table => $entry) {
            if ($entry['counts'] === [] && $this->option('tables') !== true) {
                continue;
            }

            $counts = [];

            foreach ($entry['counts'] as $what => $count) {
                $counts[] = "{$count} {$what}";
            }

            $rows[] = [$table, $entry['decision'], implode("\n", $counts), wordwrap($entry['reason'], 70)];
        }

        $this->table(['table', 'decision', 'rows', 'why'], $rows);

        if ($this->option('tables') !== true) {
            $this->line('  (tables with no affected rows are left out; --tables prints all '.count(FlipTables::all()).' decisions)');
        }

        $this->inventory('Inventory before', $report->before);
        $this->inventory('Inventory after', $report->after);
        $this->checks($report);

        if ($report->external !== []) {
            $this->section('Other systems that must follow');

            foreach ($report->external as $line) {
                $this->line('  • '.$line);
            }
        }

        $this->newLine();
    }

    private function checks(FlipReport $report): void
    {
        if ($report->checks === []) {
            return;
        }

        $this->section('Post-conditions');

        foreach ($report->checks as $check) {
            $this->line('  '.($check['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>').' '.$check['check'].($check['ok'] || $check['detail'] === '' ? '' : ': '.$check['detail']));
        }
    }

    /** @param  array<string, array<string, int>>  $inventory */
    private function inventory(string $title, array $inventory): void
    {
        if ($inventory === []) {
            return;
        }

        $this->section($title);
        $rows = [];

        foreach ($inventory as $where => $counts) {
            foreach ($counts as $what => $count) {
                $rows[] = [$where, $what, $count];
            }
        }

        $this->table(['where', 'what', 'rows'], $rows);
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    /** The options that change what the flip does, repeated on the suggested command line. */
    private function repeatOptions(): string
    {
        $repeat = '';

        foreach (['create-missing', 'keep-root-audit', 'allow-pending-jobs'] as $flag) {
            if ($this->option($flag) === true) {
                $repeat .= " --{$flag}";
            }
        }

        if ($this->option('role-conflict') !== FlipOptions::ROLE_HIGHEST) {
            $repeat .= ' --role-conflict='.(is_string($this->option('role-conflict')) ? $this->option('role-conflict') : '');
        }

        if (is_string($this->option('domain')) && $this->option('domain') !== '') {
            $repeat .= ' --domain='.$this->option('domain');
        }

        foreach ((array) $this->option('carry-client') as $client) {
            $repeat .= ' --carry-client='.(is_string($client) ? $client : '');
        }

        return $repeat;
    }
}
