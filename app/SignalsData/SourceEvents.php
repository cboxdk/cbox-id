<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class SourceEvents implements EventBus
{
    /** @param Closure(): Request $request */
    public function __construct(private EventBus $inner, private SourceLedger $ledger, private IdentityCapture $capture, private SourceRelationships $relationships, private Closure $request) {}

    public function emit(DomainEvent $event): Event
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->emit($event);
        }

        return DB::transaction(function () use ($event): Event {
            $saved = $this->inner->emit($event);
            $action = match ($event->type) {
                'user.created' => 'created',
                'user.password_reset' => 'recovery_completed',
                default => null,
            };
            $subject = $event->payload['user_id'] ?? null;
            if ($action !== null && is_string($subject)) {
                $this->capture->account($subject, $action, ($this->request)(), $event->organizationId);
            }

            $membership = $event->payload['membership_id'] ?? null;
            if (in_array($event->type, ['membership.created', 'membership.deleted'], true)
                && is_string($subject) && is_string($membership) && $event->organizationId !== null) {
                $this->relationships->membership($membership, $subject, $event->organizationId, $event->type === 'membership.created');
            }

            return $saved;
        });
    }

    public function flushPending(int $limit = 100): int
    {
        return $this->inner->flushPending($limit);
    }
}
