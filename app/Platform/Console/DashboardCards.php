<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Props\Console\DashboardCardProps;
use Cbox\Console\Kit\Facades\Console;
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
 *
 * A CARD FOR A MODULE THAT IS OFF IS ABSENT TOO. Each card links to its module's page, and
 * that page sits behind the module's console-kit feature (`RequireFeature`, a 404 when the
 * feature is inactive). The card did not ask: on cboxid.com, where analytics and
 * compliance are not switched on, the operator's dashboard offered "Sign-in activity" and
 * "View exports & retention", and both answered 404. A card registered with its feature is
 * resolved only while that feature is active, so the card and its page cannot disagree.
 */
final class DashboardCards
{
    /** @var list<array{order: int, card: Closure(): ?DashboardCardProps, feature: ?string}> */
    private array $cards = [];

    /**
     * @param  Closure(): ?DashboardCardProps  $card  resolved per request, and null when
     *                                                this module has nothing to say for the
     *                                                organization being looked at
     * @param  string|null  $feature  the console-kit feature the card's page is gated on —
     *                                the card is skipped while it is inactive
     */
    public function add(Closure $card, int $order = 100, ?string $feature = null): void
    {
        $this->cards[] = ['order' => $order, 'card' => $card, 'feature' => $feature];
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
                if ($entry['feature'] !== null && ! Console::features()->active($entry['feature'])) {
                    continue;
                }

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
