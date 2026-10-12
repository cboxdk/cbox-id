<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * WHOSE MAIL THIS IS — the brand one message is drawn and signed in, resolved once.
 *
 * Everything the frame of a mail needs, and nothing it has to look up again: the product
 * NAME the subject and the body speak of, the uploaded LOGO as an absolute URL on this
 * application, the ACCENT the button is filled with (and the two colours that stay
 * readable against it and against the white card), and the SENDER name the From line
 * carries. {@see MailBrands} decides each one — an organization's over its environment's
 * over the deployment's — and this only carries the answer.
 *
 * A value, not a lookup, so a mail is drawn in the brand of the moment it was ASKED FOR.
 * A mail rendered later (a queue worker, a test that renders after the request) has no
 * request, no host and no environment in context, and would otherwise quietly fall back
 * to the platform's own look; this travels with the message instead.
 */
final readonly class MailBrand
{
    public function __construct(
        /** The product name — "Northwind", never a hard-coded "Cbox ID". */
        public string $name,
        /** The uploaded logo as an absolute URL on this application, or null for the monogram. */
        public ?string $logo,
        /** The button fill, `#rrggbb` — the brand's light-mode primary. */
        public string $accent,
        /** Text ON the accent: white or near-black, whichever is readable. */
        public string $onAccent,
        /** The accent shade that is readable AS TEXT on the white card (4.5:1). */
        public string $accentText,
        /** The From display name, or null to leave the deployment's `MAIL_FROM_NAME`. */
        public ?string $senderName,
    ) {}

    /** The monogram drawn in place of a logo when none was uploaded. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }
}
