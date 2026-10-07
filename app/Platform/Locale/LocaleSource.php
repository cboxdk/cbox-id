<?php

declare(strict_types=1);

namespace App\Platform\Locale;

/**
 * WHERE A REQUEST'S LANGUAGE CAME FROM, in the order they are asked.
 *
 * Named rather than left implicit in an if-chain because the order is the policy, and a
 * test that wants to prove "a cookie beats the browser but loses to the relying party"
 * should be able to say so in those words. {@see LocaleResolver}.
 */
enum LocaleSource: string
{
    /** OIDC `ui_locales` on this very request — the relying party asked. */
    case UiLocales = 'ui_locales';

    /** The `ui_locales` an earlier step of this authorization carried, kept in the session. */
    case Authorization = 'authorization';

    /** The person picked a language on one of our pages. */
    case Cookie = 'cookie';

    /** The browser's own preference list. */
    case AcceptLanguage = 'accept_language';

    /** Nobody said anything: the environment's default. */
    case EnvironmentDefault = 'environment_default';
}
