<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\Webhooks\Enums\WebhookEventType;

/**
 * The event types the console offers as subscription checkboxes.
 *
 * The two consoles each hard-coded their own list — seven events on the organization
 * plane and twenty-four on the environment plane — so a tenant administrator could not
 * subscribe to a password reset, a role change or an MFA enrolment, and nothing said
 * why. The merge takes the union, which is the environment plane's list; this is the one
 * place it lives, because the create page and the detail page both render it and two
 * copies inside one capability is how the two consoles drifted in the first place.
 *
 * A plain list of strings rather than the {@see WebhookEventType} enum: the registry
 * accepts ANY non-empty type by design (the domain and its plugins emit far more than a
 * curated enum can track), and several events people already subscribe to here —
 * `user.login`, `identity.linked`, `role.unassigned` — have no case in it. Narrowing the
 * picker to the enum would silently retire live subscriptions. This is a picker's option
 * list, a serialization edge, and typing it as anything else would be a fiction.
 *
 * @see WebhookEventType for the framework's documented catalog and the `*` wildcard.
 */
class WebhookEventCatalogue
{
    /** @var list<string> */
    public const EVENTS = [
        'user.created',
        'user.updated',
        'user.login',
        'user.deactivated',
        'user.reactivated',
        'user.password_reset',
        'user.email_verified',
        'user.mfa_enrolled',
        'user.passkey_registered',
        'identity.linked',
        'organization.created',
        'organization.member_added',
        'organization.member_removed',
        'organization.member_role_changed',
        'organization.suspended',
        'organization.reactivated',
        'organization.invitation_created',
        'organization.invitation_accepted',
        'role.assigned',
        'role.unassigned',
        'directory.user.provisioned',
        'directory.user.deactivated',
        'directory.user.deprovisioned',
        'directory.group.membership_changed',
    ];
}
