<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Http\Middleware\ResolveLocale;

/**
 * WHICH LANGUAGE A MAIL GOES OUT IN.
 *
 * Every send site states it with `->locale(...)` rather than trusting the app locale at
 * the moment of sending, because the two kinds of send site want different answers and
 * the app locale is only right for one of them:
 *
 *  - SELF-SERVICE — a magic link, a password reset, a sign-up confirmation. The person
 *    who asked IS the recipient, and the hosted page they asked from has already chosen
 *    their language ({@see ResolveLocale}). The mail matches the page they were reading.
 *  - ADMIN-INITIATED — an invitation, a reset an administrator triggers, a password they
 *    set. The request is the ADMINISTRATOR's, in the English console; the recipient is
 *    somebody else. Their language is unknown, and the honest answer is the language
 *    their environment's sign-in pages default to — the one the link in the mail will
 *    open in anyway.
 *
 * An administrator's own console preference does not select the recipient's language.
 * Administrative send sites explicitly pass false; self-service follows the requester.
 */
final readonly class MailLocale
{
    public function __construct(private HostedLocales $locales) {}

    public function forRecipient(bool $recipientIsRequester = true): string
    {
        if (! $recipientIsRequester) {
            return $this->locales->default()->value;
        }

        // The CURRENT request, read at send time rather than injected: a service that holds
        // this one may outlive the request it was built in on a long-lived worker.
        $hosted = request()->attributes->get(ResolveLocale::ATTRIBUTE);

        if ($hosted instanceof HostedLocale) {
            return $hosted->value;
        }

        return $this->locales->default()->value;
    }
}
