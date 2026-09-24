<?php

declare(strict_types=1);

namespace App\Http\Props\Auth;

use App\Http\Props\Prop;

/**
 * THE PAGE BETWEEN A MAILED LINK AND THE THING IT SPENDS.
 *
 * Every single-use link this product mails — a sign-in link, an invitation, an address
 * confirmation, an Admin Portal setup link — used to be SPENT BY THE GET. That is the one
 * request a person does not make alone: Outlook Safe Links, Mimecast, Proofpoint and the
 * link unfurlers in Slack and Teams all fetch a URL before a human sees it, and the fetch
 * redeemed the token. The person who then clicked was told their link had expired — or,
 * for a sign-in link, the scanner's request was the one that got the session.
 *
 * So the GET renders this and nothing else, and the button POSTs. A scanner does not press
 * buttons, and a POST carries the CSRF token only a page this server rendered can have.
 * The page states what pressing will do, in facts rather than prose, so the one extra click
 * is also the moment somebody notices an invitation to an organization they never heard of.
 */
final readonly class LinkConfirmationProps implements Prop
{
    /**
     * @param  list<LinkFact>  $facts  what the link is about, in the order it is read
     */
    public function __construct(
        public string $heading,
        public string $lead,
        public string $actionLabel,
        public string $actionUrl,
        public array $facts = [],
        public ?string $note = null,
    ) {}

    /**
     * @return array{heading: string, lead: string, actionLabel: string, actionUrl: string, facts: list<array{label: string, value: string}>, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'heading' => $this->heading,
            'lead' => $this->lead,
            'actionLabel' => $this->actionLabel,
            'actionUrl' => $this->actionUrl,
            'facts' => array_map(static fn (LinkFact $fact): array => $fact->toArray(), $this->facts),
            'note' => $this->note,
        ];
    }
}
