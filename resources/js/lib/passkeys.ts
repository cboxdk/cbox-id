import { challengeFrom, requestStepUp, type StepUpOptions } from './stepUp';

/**
 * WEBAUTHN, over same-origin fetches only.
 *
 * The whole client half of passkeys is base64url in one direction and ArrayBuffers in the
 * other, and getting either wrong fails as "the authenticator refused" rather than as a
 * decoding error — which is why the conversion lives in one place instead of at each call
 * site.
 *
 * Every request here is same-origin by construction, which is what keeps `connect-src
 * 'self'` intact. The relying-party id is pinned server-side; nothing on this side chooses
 * an origin.
 */

const enc = (buffer: ArrayBuffer): string =>
    btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');

/**
 * base64url in, bytes out.
 *
 * `Uint8Array<ArrayBuffer>` rather than the default `ArrayBufferLike`: WebAuthn's
 * `BufferSource` will not accept a view that might be over a SharedArrayBuffer, and
 * `Uint8Array.from` widens to exactly that. Allocating the buffer says which kind it is.
 */
const dec = (value: string): Uint8Array<ArrayBuffer> => {
    const base64 = value
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(value.length / 4) * 4, '=');

    const binary = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
};

const csrf = (): string =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

/** Thrown when the server refuses, carrying its own message rather than a status code. */
export class PasskeyError extends Error {}

/**
 * The person dismissed the "Confirm it's you" dialog. Not a failure — nothing is reported,
 * exactly as for a dismissed system prompt ({@see isCancellation}).
 */
export class StepUpDeclined extends Error {}

/**
 * The server said this page is the wrong place to be — the browser is already signed in, or
 * its session ended — and the page is on its way somewhere else. Not a failure: nothing is
 * reported, and nothing is armed again behind it.
 */
export class LeavingPage extends Error {}

/** How this page leaves itself. One object, so a test can watch it without a browser. */
export const navigation = {
    assign: (url: string): void => window.location.assign(url),
    reload: (): void => window.location.reload(),
};

/** Leave this page for `url` (or load it afresh), as a navigation — never a fetch. */
function leaveFor(url: string | null): LeavingPage {
    if (url === null) {
        navigation.reload();
    } else {
        navigation.assign(url);
    }

    return new LeavingPage('Leaving the page.');
}

interface PostResult {
    redirect?: string;
    sudo?: string;
    error?: string;
    [key: string]: unknown;
}

async function post(
    url: string,
    body?: Record<string, unknown>,
    stepUp: StepUpOptions = {},
): Promise<PostResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        /*
         * NEVER FOLLOWED. These endpoints answer JSON; a redirect is the server saying the page
         * is out of date, and following it with a fetch gets nothing the page can use. Worse:
         * in login-host mode it leads to the app host, which `connect-src 'self'` refuses, so
         * the fetch failed outright and the page said only "Load failed" (Chrome on an iPhone,
         * a sign-in tab restored after the browser had signed in). Not followed, it is
         * followed as what it is — a page load.
         */
        redirect: 'manual',
        body: JSON.stringify(body ?? {}),
    });

    if (response.type === 'opaqueredirect') {
        throw leaveFor(null);
    }

    const data = (await response.json().catch(() => ({}))) as PostResult;

    if (!response.ok) {
        /*
         * A step-up is not a failure. Adding a passkey is gated on a fresh password, and
         * the server says so with a challenge rather than a bare "forbidden" — so the
         * person is asked IN PLACE, and the same request is made again once they have
         * confirmed. It used to send the browser to the full-page step-up, which came back
         * to My account and never to the ceremony: the passkey was simply never created.
         */
        const challenge = challengeFrom(response.status, response.headers, data);

        if (challenge !== null) {
            if (!(await requestStepUp(challenge, stepUp))) {
                throw new StepUpDeclined('Not confirmed.');
            }

            return post(url, body, stepUp);
        }

        /*
         * Somewhere else to be: already signed in (409), or signed out (401). The page goes
         * there itself — in login-host mode that is another origin, which only a navigation
         * may reach.
         */
        if (typeof data.redirect === 'string') {
            throw leaveFor(data.redirect);
        }

        /*
         * The page's session — and with it the CSRF token this page carries — has ended
         * (419): a tab left open past the session's lifetime. Nothing this page holds can
         * make the request valid again, so it is loaded afresh, with a new session and a new
         * token, and the person presses the button once more. WebAuthn needs that press
         * anyway: the one that brought us here has been spent.
         */
        if (response.status === 419) {
            throw leaveFor(null);
        }

        throw new PasskeyError(
            typeof data.error === 'string' ? data.error : 'That did not work. Try again.',
        );
    }

    return data;
}

/** Whether this browser can do WebAuthn at all. Affordances are hidden where it cannot. */
export function passkeysSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential !== 'undefined';
}

/*
 * The server's options, as JSON.
 *
 * Declared by SUBTRACTION from the DOM types rather than as a loose record: everything
 * the platform sends beyond the three binary fields — `rp`, `pubKeyCredParams`, the
 * authenticator selection, the timeout — is passed through untouched, and describing it
 * as `[key: string]: unknown` would mean a typo in one of them typechecked.
 */
type CreationOptions = Omit<
    PublicKeyCredentialCreationOptions,
    'challenge' | 'user' | 'excludeCredentials'
> & {
    challenge: string;
    user: Omit<PublicKeyCredentialUserEntity, 'id'> & { id: string };
    excludeCredentials?: (Omit<PublicKeyCredentialDescriptor, 'id'> & { id: string })[];
};

type RequestOptions = Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'> & {
    challenge: string;
    allowCredentials?: (Omit<PublicKeyCredentialDescriptor, 'id'> & { id: string })[];
};

/** The passkey the server stored: its id, the name it was given, and where to rename it. */
export interface RegisteredPasskey {
    id: string;
    name: string;
    renameHref: string;
}

/**
 * Enrol a new passkey on this device.
 *
 * `suggestedName` is the fallback the server uses when it cannot tell which password
 * manager or authenticator made the passkey ({@see defaultPasskeyName}); the name it
 * settled on comes back, for the person to keep or change.
 *
 * If the step-up has lapsed, the "Confirm it's you" dialog opens first and its last step is
 * a "Continue" button: the ceremony below runs inside that click, because WebAuthn needs a
 * fresh user gesture and the password round-trip has spent the one that opened it.
 */
export async function registerPasskey(
    suggestedName: string,
    base = '/passkeys',
    continueLabel = 'Continue: create passkey',
): Promise<RegisteredPasskey | null> {
    const options = (await post(`${base}/register/options`, undefined, {
        continueLabel,
    })) as unknown as CreationOptions;

    const publicKey: PublicKeyCredentialCreationOptions = {
        ...options,
        challenge: dec(options.challenge),
        user: { ...options.user, id: dec(options.user.id) },
        // The spread is the point: each descriptor is copied with its base64url id decoded
        // to the ArrayBuffer WebAuthn requires. Mutating the parsed options in place — what
        // the lint rule suggests — would leave the caller holding a half-decoded object.
        // oxlint-disable-next-line no-map-spread
        excludeCredentials: (options.excludeCredentials ?? []).map((entry) => ({
            ...entry,
            id: dec(entry.id),
        })),
    };

    const credential = (await navigator.credentials.create({
        publicKey,
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new PasskeyError('No passkey was created.');
    }

    const response = credential.response as AuthenticatorAttestationResponse;

    const stored = await post(`${base}/register`, {
        name: suggestedName,
        id: credential.id,
        type: credential.type,
        response: {
            clientDataJSON: enc(response.clientDataJSON),
            attestationObject: enc(response.attestationObject),
            transports: response.getTransports ? response.getTransports() : [],
        },
    });

    const passkey = stored.passkey;

    return typeof passkey === 'object' &&
        passkey !== null &&
        typeof (passkey as RegisteredPasskey).id === 'string' &&
        typeof (passkey as RegisteredPasskey).name === 'string' &&
        typeof (passkey as RegisteredPasskey).renameHref === 'string'
        ? (passkey as RegisteredPasskey)
        : null;
}

/**
 * A name for a passkey when nothing better is known: "Passkey on Chrome · macOS".
 *
 * The server prefers the authenticator's own name (iCloud Keychain, 1Password, Windows
 * Hello…) whenever the passkey says which it is; this is only what it falls back to, so
 * it has to be honest rather than clever — the browser and the system, read the way the
 * browser reports them.
 */
export function defaultPasskeyName(userAgent: string = navigator.userAgent): string {
    const browser = /Edg\//.test(userAgent)
        ? 'Edge'
        : /OPR\//.test(userAgent)
          ? 'Opera'
          : /Firefox\//.test(userAgent)
            ? 'Firefox'
            : /Chrome\//.test(userAgent) || /CriOS\//.test(userAgent)
              ? 'Chrome'
              : /Safari\//.test(userAgent)
                ? 'Safari'
                : null;

    const system = /iPhone/.test(userAgent)
        ? 'iPhone'
        : /iPad/.test(userAgent)
          ? 'iPad'
          : /Android/.test(userAgent)
            ? 'Android'
            : /Mac OS X|Macintosh/.test(userAgent)
              ? 'macOS'
              : /Windows/.test(userAgent)
                ? 'Windows'
                : /CrOS/.test(userAgent)
                  ? 'ChromeOS'
                  : /Linux/.test(userAgent)
                    ? 'Linux'
                    : null;

    if (browser === null && system === null) {
        return 'Passkey';
    }

    return `Passkey on ${[browser, system].filter((part): part is string => part !== null).join(' · ')}`;
}

/**
 * Whether this browser can offer passkeys in a field's autofill (WebAuthn conditional UI).
 *
 * Asked of the browser, never guessed from its name: where the answer is no, or the
 * question does not exist, nothing is armed and the "Sign in with a passkey" button is
 * the way, as before.
 */
export async function passkeyAutofillSupported(): Promise<boolean> {
    if (!passkeysSupported()) {
        return false;
    }

    try {
        return (await window.PublicKeyCredential.isConditionalMediationAvailable?.()) === true;
    } catch {
        return false;
    }
}

/**
 * Sign in with a passkey.
 *
 * Returns where to go next, decided by the server: a passkey can land on a full session,
 * or on a second factor, or on an SSO mandate that refuses it. The browser is told, not
 * asked.
 *
 * With `autofill`, nothing is shown: the request waits behind the field that carries
 * `autocomplete="… webauthn"` and resolves when the person picks a passkey from its
 * autofill. `signal` ends that wait, which the button's own ceremony must do first: a
 * browser runs one WebAuthn request at a time.
 */
export async function signInWithPasskey(
    base = '/passkeys',
    { autofill = false, signal }: { autofill?: boolean; signal?: AbortSignal } = {},
): Promise<string | null> {
    const options = (await post(
        `${base}/login/options`,
        autofill ? { autofill: true } : undefined,
    )) as unknown as RequestOptions;

    const publicKey: PublicKeyCredentialRequestOptions = {
        ...options,
        challenge: dec(options.challenge),
        // Copied rather than mutated, for the reason given in registerPasskey().
        // oxlint-disable-next-line no-map-spread
        allowCredentials: (options.allowCredentials ?? []).map((entry) => ({
            ...entry,
            id: dec(entry.id),
        })),
    };

    const credential = (await navigator.credentials.get({
        publicKey,
        ...(autofill ? { mediation: 'conditional' as const } : {}),
        ...(signal === undefined ? {} : { signal }),
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new PasskeyError('No passkey was offered.');
    }

    const response = credential.response as AuthenticatorAssertionResponse;

    const result = await post(`${base}/login`, {
        id: credential.id,
        type: credential.type,
        response: {
            clientDataJSON: enc(response.clientDataJSON),
            authenticatorData: enc(response.authenticatorData),
            signature: enc(response.signature),
            userHandle: response.userHandle === null ? null : enc(response.userHandle),
        },
    });

    return typeof result.redirect === 'string' ? result.redirect : null;
}

/**
 * Confirm it's you with a passkey — the operator plane's step-up.
 *
 * The same assertion ceremony as a sign-in, against the plane's own two endpoints. It
 * signs nobody in: the server checks that the passkey belongs to the person already
 * signed in and opens their confirmation window. Returns where the full-page screen
 * should go next (the in-place dialog ignores it and replays its own request).
 */
export async function confirmWithPasskey(
    optionsUrl: string,
    confirmUrl: string,
): Promise<string | null> {
    const options = (await post(optionsUrl)) as unknown as RequestOptions;

    const publicKey: PublicKeyCredentialRequestOptions = {
        ...options,
        challenge: dec(options.challenge),
        // Copied rather than mutated, for the reason given in registerPasskey().
        // oxlint-disable-next-line no-map-spread
        allowCredentials: (options.allowCredentials ?? []).map((entry) => ({
            ...entry,
            id: dec(entry.id),
        })),
    };

    const credential = (await navigator.credentials.get({
        publicKey,
    })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new PasskeyError('No passkey was offered.');
    }

    const response = credential.response as AuthenticatorAssertionResponse;

    const result = await post(confirmUrl, {
        id: credential.id,
        type: credential.type,
        response: {
            clientDataJSON: enc(response.clientDataJSON),
            authenticatorData: enc(response.authenticatorData),
            signature: enc(response.signature),
            userHandle: response.userHandle === null ? null : enc(response.userHandle),
        },
    });

    return typeof result.redirect === 'string' ? result.redirect : null;
}

/**
 * A cancelled ceremony is not an error worth reporting.
 *
 * `NotAllowedError` is what the browser throws when somebody dismisses the system prompt,
 * and showing "passkey failed" for a deliberate cancel trains people to distrust the
 * message that appears when something really did fail.
 */
export function isCancellation(error: unknown): boolean {
    return (
        error instanceof StepUpDeclined ||
        error instanceof LeavingPage ||
        // AbortError: this page ended the wait itself (the autofill request, when the
        // button's ceremony starts or the page goes away).
        (error instanceof DOMException &&
            (error.name === 'NotAllowedError' || error.name === 'AbortError'))
    );
}
