<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\AnnotatesTrail;
use App\Platform\EnvironmentKeyAuditLog;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Closure;

/**
 * THE ACTION RUNNING RIGHT NOW, as the audit trail needs to know it: which door it came
 * through, and whose approval it spent.
 *
 * An action records its own entries — and the framework services it calls record theirs —
 * through the one {@see AuditLog}. None of them knows the
 * door, and none should: threading a `via` through every service signature would put a
 * transport detail into domain code. So {@see ActionRunner} says it here for the duration
 * of the run, and the audit decorator ({@see EnvironmentKeyAuditLog}) adds it to every
 * entry written meanwhile. One place writes it; nothing an action does can forget it.
 *
 * A STACK, because an action may run another. The innermost run is the one an entry
 * belongs to — though in practice a nested run comes through the same door.
 *
 * Bound `scoped`: a long-lived worker must not carry one request's door into the next.
 */
final class ActionTrail
{
    /** Where the door is recorded on every entry an action causes. */
    public const string VIA = 'via';

    /** Where the spent approval is recorded, on an action that waited for one. */
    public const string APPROVAL = 'approval_id';

    /** Who gave it: the subject id of the person who approved. */
    public const string APPROVED_BY = 'approved_by';

    /** @var list<array{via: ActionVia, approval: ?string, approver: ?string, notes: array<string, string>}> */
    private array $stack = [];

    /**
     * Run $callback as an action that came through $via.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(ActionVia $via, Closure $callback): mixed
    {
        $this->stack[] = ['via' => $via, 'approval' => null, 'approver' => null, 'notes' => []];

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * Run $callback as no action at all: what the platform does for ITSELF on the way to
     * running one — registering its own step-up client the first time an approval is asked
     * for — is not the act of whoever's action happened to need it, nor did it come
     * through their door.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function outside(Closure $callback): mixed
    {
        $stack = $this->stack;
        $this->stack = [];

        try {
            return $callback();
        } finally {
            $this->stack = $stack;
        }
    }

    /** The running action spent $approvalId, given by $approverSubjectId. */
    public function approved(string $approvalId, ?string $approverSubjectId): void
    {
        $top = array_key_last($this->stack);

        if ($top === null) {
            return;
        }

        $this->stack[$top]['approval'] = $approvalId;
        $this->stack[$top]['approver'] = $approverSubjectId;
    }

    /**
     * Whatever the running action's principal adds to every entry it causes
     * ({@see AnnotatesTrail}) — the Admin Portal's link and its minter.
     *
     * @param  array<string, string>  $notes
     */
    public function annotate(array $notes): void
    {
        $top = array_key_last($this->stack);

        if ($top === null) {
            return;
        }

        $this->stack[$top]['notes'] = [...$this->stack[$top]['notes'], ...$notes];
    }

    public function via(): ?ActionVia
    {
        $top = array_key_last($this->stack);

        return $top === null ? null : $this->stack[$top]['via'];
    }

    /**
     * What every entry written now gains: the door, and the approval when there was one.
     * Empty outside an action, so an entry nobody ran an action for is left exactly as it
     * was written.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        $top = array_key_last($this->stack);

        if ($top === null) {
            return [];
        }

        $frame = $this->stack[$top];

        return array_filter([
            ...$frame['notes'],
            self::VIA => $frame['via']->value,
            self::APPROVAL => $frame['approval'],
            self::APPROVED_BY => $frame['approver'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
