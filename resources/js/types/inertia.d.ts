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
             * What the legacy-login endpoint said about one address.
             *
             * A sentence about somebody's account at ANOTHER system, so it never becomes a
             * page prop: props are written into the browser's history entry, and this is
             * an answer that should live exactly as long as the reply that carried it.
             */
            probeResult?: string;
            /**
             * A credential revealed exactly once.
             *
             * On the flash channel rather than in props precisely because props are
             * written into the history entry: a signing secret there is retrievable by
             * pressing Back, long after the page that showed it has gone.
             */
            newSecret?: string;
            /**
             * A management-plane API key's plaintext, revealed exactly once.
             *
             * Same reasoning as the signing secret: a full-authority credential written
             * into a history entry is readable by pressing Back.
             */
            freshKey?: string;
            /**
             * What the "send the link again" button on the launchpad has to say.
             *
             * Not `status`: the toaster shows that for every mutation on the console, and
             * a rate-limit sentence announced as a success toast is the wrong shape. It
             * belongs beside the button that asked, on the render that answered.
             */
            resendNotice?: string;
            /**
             * An OAuth client's plaintext secret, revealed exactly once — freshly minted
             * at registration or by a rotation.
             *
             * Same reasoning as every other credential on this channel: props are written
             * into the browser's history entry, so one there is readable by pressing Back
             * long after the page that showed it has gone.
             */
            revealedSecret?: string;
            /**
             * A single-use Admin Portal URL, revealed once.
             *
             * The link admits its holder to a tenant's SSO setup with NO ACCOUNT AT ALL,
             * which makes it a credential in a URL — and the same rule follows: not a
             * prop, because props are written into the browser's history entry.
             */
            portalUrl?: string;
            /** The DNS challenge for a domain just added — shown once, re-issued if lost. */
            dns?: { host: string; token: string; domain: string };
            /**
             * A directory's SCIM bearer token, revealed exactly once.
             *
             * It authenticates every inbound provisioning call for one organization, so it
             * is a credential and follows the same rule: never a prop.
             */
            newToken?: string;
            /**
             * A password an administrator just set for somebody else, shown once.
             *
             * The most complete takeover this console offers, and the same rule as every
             * other credential here: never a prop, because props are written into the
             * browser's history entry and would be readable by pressing Back.
             */
            issuedPassword?: string;
            /**
             * A freshly-minted TOTP secret, and the QR code that carries it — the same
             * value in two forms, shown once.
             *
             * It is the second factor: whoever holds it can produce every code the account
             * will ever accept. So it follows the rule every other credential here does —
             * never a prop, because props are written into the browser's history entry and
             * would be readable by pressing Back long after the page that showed it is gone.
             */
            mfaSecret?: string;
            /** The SVG for {@see mfaSecret}, rendered server-side from the otpauth:// URI. */
            mfaQrCode?: string;
            /**
             * Recovery codes, generated at enrolment or regenerated on request.
             *
             * Each one signs somebody in with no second factor at all, which makes the set
             * a credential — and the page says "shown only once" because this channel is
             * what makes that true.
             */
            recoveryCodes?: string[];
            /**
             * What just happened on the device-approval screen: `approved` or `denied`.
             *
             * True for exactly one render. As a prop it would live in the history entry and
             * announce "Device connected" again when somebody pressed Back on a page that
             * is, by then, about nothing.
             */
            deviceOutcome?: 'approved' | 'denied';
            /** Why a device link could not be resolved — same one-render reasoning. */
            deviceError?: string;
            /**
             * Whose setup just finished, carried to the "All set" page.
             *
             * On the flash channel because the session that KNEW it is the thing finishing
             * ended — there is nothing left to read it from on the next request, and it is
             * true for exactly that one render.
             */
            portalOrganization?: string | null;
            /** The name a freshly-minted SCIM directory was given, beside its token. */
            newTokenName?: string;
            /** SAML fields parsed out of an IdP's metadata, to fill the create form once. */
            metadata?: { idp_entity_id: string; idp_sso_url: string; idp_x509cert: string };
        };
    }
}
