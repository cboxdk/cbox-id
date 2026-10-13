import type { router as InertiaRouter } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

/**
 * STEP-UP, IN PLACE — "Confirm it's you" without leaving the page.
 *
 * A gated request the server will not run yet is answered with a 403 carrying the
 * `X-Cbox-Step-Up` header and a `step_up` body (App\Platform\StepUpPrompt). This module is
 * the client half: it recognises that answer, asks the person in a dialog on the page they
 * are on, confirms against the plane's own endpoint, and then lets the caller run the
 * action it was holding.
 *
 * It replaced a detour. The server used to send the browser to the full-page `/sudo`
 * screen and bring it back to the PAGE — never to the action — so somebody who pressed
 * "Add passkey" and typed their password landed back on My account with no passkey, and
 * every other gated button behaved the same way.
 *
 * TWO CALLERS, ONE DIALOG.
 *
 *  - Every Inertia visit, through {@see installStepUp}: the visit that was refused is
 *    replayed, method, URL, data, options and callbacks all, once the window is open.
 *  - A JSON ceremony (the passkey enrolment), through {@see requestStepUp} directly — with
 *    a `continueLabel`, because WebAuthn needs a fresh click to run in, and the password
 *    the person just typed is not one (see that option).
 *
 * THE PLANES STAY SEPARATE. The server names the endpoint to confirm against, and each
 * plane has its own: a confirmation on the organization plane is never sent to, or
 * counted by, the environment plane's.
 */

export type StepUpPlane = 'organization' | 'environment';

/** What the server asked for: which plane, where to confirm, and why. */
export interface StepUpChallenge {
    plane: StepUpPlane;
    /** The plane's confirm endpoint, same-origin by construction (checked here again). */
    confirm: string;
    /** The sentence the action gave, or null for a route that only says it is protected. */
    reason: string | null;
}

export interface StepUpOptions {
    /**
     * A last step, after the password is accepted: a button with this label that the
     * person presses to carry on.
     *
     * For a ceremony that needs TRANSIENT USER ACTIVATION — WebAuthn's
     * `navigator.credentials.create()` does, and Safari enforces it strictly. Submitting the
     * password was a gesture, but the confirmation round-trip spends it; calling the
     * ceremony afterwards silently would be refused (or, in Safari, ignored). So the
     * promise resolves inside this button's click handler, and the caller's continuation
     * runs in that gesture — exactly as a click on "Add passkey" did before.
     */
    continueLabel?: string;
}

/** The header that marks a step-up refusal (lower-cased, as both HTTP clients report it). */
export const STEP_UP_HEADER = 'x-cbox-step-up';

const PLANES: readonly StepUpPlane[] = ['organization', 'environment'];

/**
 * The challenge in a response, or null for any response that is not one.
 *
 * The header is what makes it one — a 403 from anywhere else (a policy refusal, a
 * suspended account) must never open a password prompt, or the dialog becomes a way to
 * phish a password out of a page that merely failed. The confirm URL must also be ours:
 * a body that named another origin is ignored rather than posted a password to.
 */
export function challengeFrom(
    status: number,
    headers: Headers | Record<string, string | undefined>,
    data: unknown,
): StepUpChallenge | null {
    const header =
        headers instanceof Headers ? headers.get(STEP_UP_HEADER) : headers[STEP_UP_HEADER];

    if (status !== 403 || typeof header !== 'string' || header === '') {
        return null;
    }

    const body = typeof data === 'string' ? safeParse(data) : data;
    const stepUp = isRecord(body) && isRecord(body.step_up) ? body.step_up : null;

    if (stepUp === null) {
        return null;
    }

    const plane = PLANES.find((candidate) => candidate === stepUp.plane);
    const confirm = typeof stepUp.confirm === 'string' ? stepUp.confirm : header;

    if (plane === undefined || !sameOrigin(confirm)) {
        return null;
    }

    return {
        plane,
        confirm,
        reason: typeof stepUp.reason === 'string' && stepUp.reason !== '' ? stepUp.reason : null,
    };
}

/*
 * THE QUEUE. One dialog at a time; requests for the plane already being asked about join
 * it (one password opens the window for all of them), and a request for the other plane
 * waits its turn rather than being answered by the wrong confirmation.
 */

interface Waiter {
    options: StepUpOptions;
    resolve: (confirmed: boolean) => void;
}

export interface StepUpPrompt {
    /** Which ask this is — a new one gets a fresh, empty form. */
    id: number;
    challenge: StepUpChallenge;
    /** The password was accepted and a waiter wants a click to carry on. */
    continueLabel: string | null;
}

interface Entry {
    id: number;
    challenge: StepUpChallenge;
    waiters: Waiter[];
    confirmed: boolean;
}

let queue: Entry[] = [];
let nextId = 1;
let snapshot: StepUpPrompt | null = null;
const listeners = new Set<() => void>();

function publish(): void {
    const head = queue[0];

    snapshot =
        head === undefined
            ? null
            : {
                  id: head.id,
                  challenge: head.challenge,
                  continueLabel: head.confirmed
                      ? (head.waiters.find((waiter) => waiter.options.continueLabel)?.options
                            .continueLabel ?? null)
                      : null,
              };

    listeners.forEach((listener) => listener());
}

/**
 * Ask the person to confirm it's them. Resolves `true` once the plane's window is open
 * (and, with `continueLabel`, once they press it), `false` if they dismiss the dialog.
 */
export function requestStepUp(
    challenge: StepUpChallenge,
    options: StepUpOptions = {},
): Promise<boolean> {
    return new Promise<boolean>((resolve) => {
        const head = queue[0];

        // The password for this plane was just accepted and only a last step is showing:
        // the window is open, so a request without a last step of its own simply runs.
        if (
            head?.confirmed === true &&
            head.challenge.plane === challenge.plane &&
            options.continueLabel === undefined
        ) {
            resolve(true);

            return;
        }

        const existing = queue.find(
            (entry) => !entry.confirmed && entry.challenge.plane === challenge.plane,
        );

        if (existing !== undefined) {
            existing.waiters.push({ options, resolve });

            // A later request knows why; an earlier one that did not should not hide it.
            if (existing.challenge.reason === null && challenge.reason !== null) {
                existing.challenge = { ...existing.challenge, reason: challenge.reason };
            }
        } else {
            queue = [
                ...queue,
                { id: nextId++, challenge, waiters: [{ options, resolve }], confirmed: false },
            ];
        }

        publish();
    });
}

/**
 * The password was accepted. Everybody waiting on this plane without a last step carries
 * on now; one with a last step is shown it.
 */
export function stepUpConfirmed(): void {
    const head = queue[0];

    if (head === undefined) {
        return;
    }

    head.confirmed = true;

    const later = head.waiters.filter((waiter) => waiter.options.continueLabel !== undefined);
    head.waiters
        .filter((waiter) => waiter.options.continueLabel === undefined)
        .forEach((waiter) => waiter.resolve(true));
    head.waiters = later;

    if (later.length === 0) {
        queue = queue.slice(1);
    }

    publish();
}

/**
 * The last step was pressed. Called from that button's click handler, so whatever the
 * waiter does next runs inside the person's gesture.
 */
export function stepUpContinued(): void {
    const head = queue[0];

    if (head === undefined) {
        return;
    }

    queue = queue.slice(1);
    head.waiters.forEach((waiter) => waiter.resolve(true));
    publish();
}

/** Dismissed — nothing that was waiting runs. */
export function stepUpCancelled(): void {
    const head = queue[0];

    if (head === undefined) {
        return;
    }

    queue = queue.slice(1);
    // Already-confirmed: the window IS open, only the last step was declined. Either way the
    // action that wanted the click does not run without one.
    head.waiters.forEach((waiter) => waiter.resolve(false));
    publish();
}

/** The prompt the dialog should show now, or null. */
export function useStepUpPrompt(): StepUpPrompt | null {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => snapshot,
        () => null,
    );
}

export type ConfirmOutcome = { ok: true } | { ok: false; error: string };

/**
 * Send the password to the plane's confirm endpoint, as JSON. The server answers with the
 * same throttle as the full-page form (five attempts, then a wait) and the same error
 * sentence; nothing here retries.
 */
export async function confirmStepUp(
    challenge: StepUpChallenge,
    password: string,
): Promise<ConfirmOutcome> {
    if (!sameOrigin(challenge.confirm)) {
        return { ok: false, error: 'That did not work. Reload the page and try again.' };
    }

    let response: Response;

    try {
        response = await fetch(challenge.confirm, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ password }),
        });
    } catch {
        return {
            ok: false,
            error: 'You appear to be offline. Check your connection and try again.',
        };
    }

    if (response.ok) {
        return { ok: true };
    }

    const data: unknown = await response.json().catch(() => null);
    const errors = isRecord(data) && isRecord(data.errors) ? data.errors : null;
    const field = errors !== null && Array.isArray(errors.password) ? errors.password[0] : null;

    if (typeof field === 'string') {
        return { ok: false, error: field };
    }

    if (response.status === 419) {
        return { ok: false, error: 'This page has expired. Reload it and try again.' };
    }

    if (isRecord(data) && typeof data.error === 'string') {
        return { ok: false, error: data.error };
    }

    return { ok: false, error: 'That did not work. Try again.' };
}

type Router = typeof InertiaRouter;

let installed = false;

/**
 * Teach every Inertia visit to step up in place.
 *
 * WRAPPED AT `visit`, the one door every other helper walks through — `post`, `patch`,
 * `delete`, `reload`, `useForm`'s submit and `<Link>` all end there — so the visit that
 * was refused is still in hand, exactly as the page made it, when the person confirms.
 * The global `httpException` event cannot do this: it carries the response and not the
 * visit, so it could open a dialog but never finish the click.
 *
 * The visit's own `onHttpException` still runs for every other refusal, and runs first
 * in the sense that matters: this one only claims a response that is a step-up.
 */
export function installStepUp(router: Router): void {
    if (installed) {
        return;
    }

    installed = true;

    const visit = router.visit.bind(router);

    const wrapped: Router['visit'] = (href, options = {}) => {
        visit(href, {
            ...options,
            onHttpException: (response) => {
                const challenge = challengeFrom(response.status, response.headers, response.data);

                if (challenge === null) {
                    return options.onHttpException?.(response);
                }

                void requestStepUp(challenge).then((confirmed) => {
                    if (!confirmed) {
                        return;
                    }

                    // A refusal may have been cached by a hover prefetch; replaying into it
                    // would ask again for a window that is already open.
                    router.flushAll();
                    wrapped(href, options);
                });

                // Claimed: no error modal, no global event — the dialog is the answer.
                return false;
            },
        });
    };

    router.visit = wrapped;
}

function csrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function sameOrigin(url: string): boolean {
    try {
        return new URL(url, window.location.href).origin === window.location.origin;
    } catch {
        return false;
    }
}

function safeParse(text: string): unknown {
    try {
        return JSON.parse(text) as unknown;
    } catch {
        return null;
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** For tests: forget every pending prompt and the installation. */
export function resetStepUpForTests(): void {
    queue = [];
    installed = false;
    publish();
}
