<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\Webhooks\Enums\WebhookEventType;
use Cbox\Id\Webhooks\ValueObjects\WebhookEventDescriptor;

/**
 * The event types the console offers as subscription checkboxes — the framework's
 * {@see WebhookEventType::offered()}, and nothing kept by hand.
 *
 * This was a hand-kept list of twenty-four strings, and four of them —
 * `user.password_reset`, `user.email_verified`, `user.mfa_enrolled`,
 * `user.passkey_registered` — are written to the audit trail and never put on the event
 * bus, so an endpoint subscribed to them waited for deliveries that could not come. The
 * list also offered the legacy `organization.member_*` names and missed every event the
 * framework has catalogued since. The framework's catalogue knows which events are
 * emitted and which are superseded; the picker reads it.
 *
 * AN EXISTING SUBSCRIPTION IS NEVER DROPPED BY AN EDIT. An endpoint may already name an
 * event the picker no longer offers — a legacy name, or one of the four above. Its edit
 * form lists those too ({@see forEndpoint()}), so saving the form keeps them unless the
 * administrator unticks them, rather than silently narrowing what a live integration
 * receives.
 */
final class WebhookEventCatalogue
{
    /** The sentence a submission naming an event the picker does not list gets. */
    public const string REFUSAL = 'Choose events from the list. An endpoint can only subscribe to events Cbox ID sends.';

    /**
     * What a new subscription may choose from: {@see WebhookEventType::offered()}, in the
     * catalogue's grouped display order (users, organizations, …) rather than the enum's
     * declaration order, so related events sit together.
     *
     * @return list<string>
     */
    public static function offered(): array
    {
        return array_values(array_map(
            static fn (WebhookEventDescriptor $event): string => $event->name(),
            array_filter(WebhookEventType::catalogue(), static fn (WebhookEventDescriptor $event): bool => $event->isOffered()),
        ));
    }

    /**
     * What an existing endpoint's edit form lists: every offered event, then whatever it
     * is already subscribed to that is not offered, in the order it was subscribed.
     *
     * @param  list<string>  $subscribed
     * @return list<string>
     */
    public static function forEndpoint(array $subscribed): array
    {
        $offered = self::offered();

        return [...$offered, ...array_values(array_diff($subscribed, $offered))];
    }
}
