import '@inertiajs/core';
import type { SharedProps } from './index';

/**
 * THE TWO CHANNELS A PAGE CAN BE TOLD SOMETHING ON, and the rule for which to use.
 *
 * SHARED PROPS carry state: who is signed in, what the chrome looks like, what realm this
 * is. They are persisted in the browser's history entry, which is what makes going back
 * instant and is exactly right for state.
 *
 * INERTIA'S FLASH carries a ONE-SHOT: a secret revealed once, a step in a flow, a
 * confirmation. It is NOT persisted in history state, and that is the whole reason to use
 * it — a one-time signing secret written into a history entry is a credential at rest in
 * the browser's session store, retrievable by pressing Back.
 *
 * There is a third, and it is deliberate: Laravel's own session flash (`status`, `error`)
 * reaches React through the `flash` SHARED PROP. It is the bridge between the two stacks
 * while the port runs — a Volt page redirecting to a React one, or the reverse, uses
 * `->with('status', …)` and it works in both directions. It goes when Volt does.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;

        flashDataType: {
            /** Sign-in: the identifier step passed, so the password form is drawn. */
            identified?: boolean;
            /** Sign-in: the connection this address may use, and whether it leads the page. */
            ssoOffer?: string | null;
            ssoOfferLeads?: boolean;
            /** Sign-in: a magic link was sent to this address — shown the same either way. */
            magicSentTo?: string;
            /** Local installs only: the link itself, when there is no mail transport. */
            magicUrl?: string | null;
            /** Sign-in: this address signs in somewhere else, and no password will be taken. */
            mandate?: { organization: string; startUrl: string | null; reason: string };
            /** Password reset: the address a link was sent to, and the link on a local install. */
            sentTo?: string;
            devResetUrl?: string | null;
            /** Signup: the risk scorer asked for a CAPTCHA on this submission. */
            challenged?: boolean;
            /** Step-up: a fresh code was sent. */
            resent?: string;
            /**
             * A credential revealed exactly once.
             *
             * On the flash channel rather than in props precisely because props are
             * written into the history entry: a signing secret there is retrievable by
             * pressing Back, long after the page that showed it has gone.
             */
            newSecret?: string;
        };
    }
}
