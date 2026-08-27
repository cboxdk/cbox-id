<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Console\DashboardCardProps;
use Closure;
use Throwable;

/**
 * WHAT THE MODULES ADD TO THE DASHBOARD.
 *
 * The console-kit slot registry this replaces took a closure returning HTML. That is the
 * right shape for a blade console and the wrong one for a client-rendered page, and the
 * wrong-shaped fix — handing module markup to `dangerouslySetInnerHTML` — would have let
 * five modules emit arbitrary HTML into the console's own layout forever.
 *
 * Held HERE rather than in the package because the package is a separate release and this
 * port is one PR. The registration call is one line per module either way, so upstreaming
 * it later is a rename.
 *
 * A CARD THAT THROWS IS A CARD THAT IS ABSENT, never a dashboard that is a stack trace.
 * Each of the five modules wrapped its own body in a `try` for exactly this reason — a
 * module reading a store that is not provisioned yet must not take down the page that
 * every administrator lands on — and doing it once here is what stops the sixth forgetting.
 */
final class DashboardCards
{
    /** @var list<array{order: int, card: Closure(): ?DashboardCardProps}> */
    private array $cards = [];

    /**
     * @param  Closure(): ?DashboardCardProps  $card  resolved per request, and null when
     *                                                this module has nothing to say for the
     *                                                organization being looked at
     */
    public function add(Closure $card, int $order = 100): void
    {
        $this->cards[] = ['order' => $order, 'card' => $card];
    }

    /**
     * @return list<DashboardCardProps>
     */
    public function resolve(): array
    {
        $sorted = $this->cards;

        usort($sorted, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $resolved = [];

        foreach ($sorted as $entry) {
            try {
                $card = ($entry['card'])();
            } catch (Throwable) {
                continue;
            }

            if ($card instanceof DashboardCardProps) {
                $resolved[] = $card;
            }
        }

        return $resolved;
    }
}
