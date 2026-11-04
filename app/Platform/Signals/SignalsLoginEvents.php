<?php

declare(strict_types=1);

namespace App\Platform\Signals;

use App\Platform\Radar\RadarDevices;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\Models\Event;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Closure;
use Illuminate\Http\Request;

/** Enrich the existing durable public ID feed; never emit a successful login on assessment. */
final readonly class SignalsLoginEvents implements EventBus
{
    /** @param Closure(): Request $request Resolve the current request even in long-lived workers. */
    public function __construct(
        private EventBus $events,
        private EnvironmentContext $environments,
        private RadarDevices $devices,
        private Closure $request,
        private string $environment,
    ) {}

    public function emit(DomainEvent $event): Event
    {
        if ($event->type === 'user.session_started'
            && $this->environments->current()?->environmentKey() === $this->environment) {
            $event = new DomainEvent($event->type, [...$event->payload,
                'outcome' => 'success',
                'device_id' => $this->devices->identify(($this->request)())->deviceHash,
            ], $event->organizationId);
        }

        return $this->events->emit($event);
    }

    public function flushPending(int $limit = 100): int
    {
        return $this->events->flushPending($limit);
    }
}
