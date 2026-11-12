<?php

declare(strict_types=1);

namespace App\Platform\Realm;

/**
 * Everything one run of {@see RealmFlip} found, decided and did, in the order it happened.
 *
 * The command prints it; the feature test reads it. A BLOCKER is a precondition that
 * failed: nothing is executed while there is one. A WARNING is something a person should
 * read before `--execute` and that the flip handles the way the line says.
 */
final class FlipReport
{
    /** @var list<string> */
    public array $blockers = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $facts = [];

    /** @var array<string, array{decision: string, reason: string, counts: array<string, int>}> */
    public array $tables = [];

    /** @var array<string, array<string, int>> inventory name => [label => count] */
    public array $before = [];

    /** @var array<string, array<string, int>> */
    public array $after = [];

    /** @var list<string> */
    public array $external = [];

    /** @var list<array{check: string, ok: bool, detail: string}> */
    public array $checks = [];

    /** @var array<string, array<string, string|null>> kind => [old id => new id or null] */
    public array $ids = [];

    public bool $committed = false;

    public bool $alreadyFlipped = false;

    public ?string $rootId = null;

    public ?string $realmId = null;

    public function block(string $line): void
    {
        $this->blockers[] = $line;
    }

    public function warn(string $line): void
    {
        $this->warnings[] = $line;
    }

    public function fact(string $line): void
    {
        $this->facts[] = $line;
    }

    public function check(string $check, bool $ok, string $detail = ''): void
    {
        $this->checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    }

    public function count(string $table, string $what, int $rows): void
    {
        if ($rows === 0) {
            return;
        }

        $this->tables[$table]['counts'][$what] = ($this->tables[$table]['counts'][$what] ?? 0) + $rows;
    }

    public function decided(string $table, string $decision, string $reason): void
    {
        $this->tables[$table] = ['decision' => $decision, 'reason' => $reason, 'counts' => $this->tables[$table]['counts'] ?? []];
    }

    public function blocked(): bool
    {
        return $this->blockers !== [];
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function failedChecks(): array
    {
        return array_values(array_map(
            static fn (array $check): string => trim($check['check'].': '.$check['detail'], ': '),
            array_filter($this->checks, static fn (array $check): bool => ! $check['ok']),
        ));
    }
}
