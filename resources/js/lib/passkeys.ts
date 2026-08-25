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

interface PostResult {
    redirect?: string;
    sudo?: string;
    error?: string;
    [key: string]: unknown;
}

async function post(url: string, body?: Record<string, unknown>): Promise<PostResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body ?? {}),
    });

    const data = (await response.json().catch(() => ({}))) as PostResult;

    if (!response.ok) {
        /*
         * A step-up is not a failure. Removing a passkey is gated on a fresh password,
         * and the server answers with where to go and prove it — so the person is sent
         * there and comes back, rather than being told "forbidden" at the one moment they
         * are trying to secure their own account.
         *
         * The promise never settles because the page is navigating away; resolving it
         * would let the caller show a result for a flow that is no longer on screen.
         */
        if (response.status === 403 && typeof data.sudo === 'string') {
            window.location.assign(data.sudo);

            return new Promise<PostResult>(() => {});
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

/** Enrol a new passkey on this device. */
export async function registerPasskey(name: string, base = '/passkeys'): Promise<void> {
    const options = (await post(`${base}/register/options`)) as unknown as CreationOptions;

    const publicKey: PublicKeyCredentialCreationOptions = {
        ...options,
        challenge: dec(options.challenge),
        user: { ...options.user, id: dec(options.user.id) },
        excludeCredentials: (options.excludeCredentials ?? []).map((entry) => ({
            ...entry,
            id: dec(entry.id),
        })),
    };

    const credential = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null;

    if (credential === null) {
        throw new PasskeyError('No passkey was created.');
    }

    const response = credential.response as AuthenticatorAttestationResponse;

    await post(`${base}/register`, {
        name,
        id: credential.id,
        type: credential.type,
        response: {
            clientDataJSON: enc(response.clientDataJSON),
            attestationObject: enc(response.attestationObject),
            transports: response.getTransports ? response.getTransports() : [],
        },
    });
}

/**
 * Sign in with a passkey.
 *
 * Returns where to go next, decided by the server: a passkey can land on a full session,
 * or on a second factor, or on an SSO mandate that refuses it. The browser is told, not
 * asked.
 */
export async function signInWithPasskey(base = '/passkeys'): Promise<string | null> {
    const options = (await post(`${base}/login/options`)) as unknown as RequestOptions;

    const publicKey: PublicKeyCredentialRequestOptions = {
        ...options,
        challenge: dec(options.challenge),
        allowCredentials: (options.allowCredentials ?? []).map((entry) => ({
            ...entry,
            id: dec(entry.id),
        })),
    };

    const credential = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential | null;

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
 * A cancelled ceremony is not an error worth reporting.
 *
 * `NotAllowedError` is what the browser throws when somebody dismisses the system prompt,
 * and showing "passkey failed" for a deliberate cancel trains people to distrust the
 * message that appears when something really did fail.
 */
export function isCancellation(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'NotAllowedError';
}
