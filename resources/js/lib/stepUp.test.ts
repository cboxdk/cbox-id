import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    challengeFrom,
    confirmStepUp,
    installStepUp,
    requestStepUp,
    resetStepUpForTests,
    type StepUpChallenge,
    stepUpCancelled,
    stepUpConfirmed,
    stepUpContinued,
} from './stepUp';

const organization: StepUpChallenge = {
    plane: 'organization',
    confirm: `${window.location.origin}/sudo`,
    reason: 'Adding a passkey gives you a new way to sign in.',
};

const body = (stepUp: Record<string, unknown>) => ({
    error: "Confirm it's you first",
    sudo: '/sudo',
    step_up: stepUp,
});

afterEach(() => {
    resetStepUpForTests();
    vi.restoreAllMocks();
});

describe('challengeFrom', () => {
    it('reads a step-up refusal', () => {
        expect(
            challengeFrom(
                403,
                { 'x-cbox-step-up': organization.confirm },
                body({ plane: 'organization', confirm: organization.confirm, reason: 'Why.' }),
            ),
        ).toEqual({ plane: 'organization', confirm: organization.confirm, reason: 'Why.' });
    });

    it('reads the operator plane only with a same-origin passkey endpoint', () => {
        const confirm = '/admin/confirm';
        const operator = (passkey: unknown): unknown => ({
            step_up: { plane: 'operator', confirm, reason: 'Why.', passkey },
        });

        expect(
            challengeFrom(
                403,
                { 'x-cbox-step-up': confirm },
                operator({ options: '/admin/confirm/options' }),
            ),
        ).toEqual({
            plane: 'operator',
            confirm,
            reason: 'Why.',
            passkeyOptions: '/admin/confirm/options',
        });

        // Never a password prompt for this plane, and never a ceremony against another origin.
        expect(challengeFrom(403, { 'x-cbox-step-up': confirm }, operator(undefined))).toBeNull();
        expect(
            challengeFrom(
                403,
                { 'x-cbox-step-up': confirm },
                operator({ options: 'https://evil.example/options' }),
            ),
        ).toBeNull();
    });

    it('reads one from a Headers object and a JSON string body', () => {
        const headers = new Headers({
            'X-Cbox-Step-Up': '/orgs/acme/projects/web/environments/production/sudo',
        });

        expect(
            challengeFrom(
                403,
                headers,
                JSON.stringify(
                    body({
                        plane: 'environment',
                        confirm: '/orgs/acme/projects/web/environments/production/sudo',
                        reason: null,
                    }),
                ),
            ),
        ).toEqual({
            plane: 'environment',
            confirm: '/orgs/acme/projects/web/environments/production/sudo',
            reason: null,
        });
    });

    it('never treats another 403 as a password prompt', () => {
        // No header: a policy refusal, a suspended account — not ours to ask a password for.
        expect(
            challengeFrom(403, {}, body({ plane: 'organization', confirm: '/sudo' })),
        ).toBeNull();
        expect(
            challengeFrom(422, { 'x-cbox-step-up': '/sudo' }, body({ plane: 'organization' })),
        ).toBeNull();
        expect(challengeFrom(403, { 'x-cbox-step-up': '/sudo' }, { error: 'nope' })).toBeNull();
        expect(
            challengeFrom(
                403,
                { 'x-cbox-step-up': '/sudo' },
                body({ plane: 'workspace', confirm: '/sudo' }),
            ),
        ).toBeNull();
    });

    it('refuses to post a password to another origin', () => {
        expect(
            challengeFrom(
                403,
                { 'x-cbox-step-up': 'https://evil.test/sudo' },
                body({ plane: 'organization', confirm: 'https://evil.test/sudo' }),
            ),
        ).toBeNull();
    });
});

describe('the queue', () => {
    it('resolves every waiter on the same plane with one confirmation', async () => {
        const first = requestStepUp(organization);
        const second = requestStepUp({ ...organization, reason: null });

        stepUpConfirmed();

        await expect(first).resolves.toBe(true);
        await expect(second).resolves.toBe(true);
    });

    it('never lets one plane answer for the other', async () => {
        const org = requestStepUp(organization);
        const env = requestStepUp({
            plane: 'environment',
            confirm: '/orgs/acme/projects/web/environments/production/sudo',
            reason: null,
        });
        let envSettled = false;
        void env.then(() => {
            envSettled = true;
        });

        stepUpConfirmed();
        await expect(org).resolves.toBe(true);
        await Promise.resolve();

        // The environment plane is still waiting for ITS confirmation.
        expect(envSettled).toBe(false);

        stepUpCancelled();
        await expect(env).resolves.toBe(false);
    });

    it('holds a ceremony until its continue button is pressed', async () => {
        const ceremony = requestStepUp(organization, { continueLabel: 'Continue: create passkey' });
        let settled = false;
        void ceremony.then(() => {
            settled = true;
        });

        stepUpConfirmed();
        await Promise.resolve();
        expect(settled).toBe(false);

        stepUpContinued();
        await expect(ceremony).resolves.toBe(true);
    });

    it('runs nothing when dismissed', async () => {
        const pending = requestStepUp(organization);

        stepUpCancelled();

        await expect(pending).resolves.toBe(false);
    });
});

describe('confirmStepUp', () => {
    it('posts the password as JSON to the plane it was asked for', async () => {
        const fetch = vi
            .spyOn(window, 'fetch')
            .mockResolvedValue(new Response(JSON.stringify({ confirmed: true }), { status: 200 }));

        await expect(confirmStepUp(organization, 'secret')).resolves.toEqual({ ok: true });

        const [url, init] = fetch.mock.calls[0] ?? [];
        expect(url).toBe(organization.confirm);
        expect(init?.method).toBe('POST');
        expect((init?.headers as Record<string, string> | undefined)?.Accept).toBe(
            'application/json',
        );
        expect(init?.body).toBe(JSON.stringify({ password: 'secret' }));
    });

    it('returns the server sentence for a wrong password', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'x',
                    errors: { password: ['That password is incorrect.'] },
                }),
                { status: 422 },
            ),
        );

        await expect(confirmStepUp(organization, 'wrong')).resolves.toEqual({
            ok: false,
            error: 'That password is incorrect.',
        });
    });
});

describe('installStepUp', () => {
    it('claims a step-up refusal and replays the same visit once confirmed', async () => {
        const calls: { href: unknown; options: Record<string, unknown> }[] = [];
        const router = {
            visit: (href: unknown, options: Record<string, unknown> = {}) => {
                calls.push({ href, options });
            },
            flushAll: vi.fn<() => void>(),
        };
        const onSuccess = vi.fn<() => void>();

        installStepUp(router as never);
        router.visit('/account/sessions/revoke-others', { method: 'post', onSuccess });

        const first = calls[0];
        const handler = first?.options.onHttpException as (response: unknown) => unknown;
        const claimed = handler({
            status: 403,
            headers: { 'x-cbox-step-up': organization.confirm },
            data: body({ plane: 'organization', confirm: organization.confirm, reason: null }),
        });

        // No error modal: the dialog is the answer.
        expect(claimed).toBe(false);

        stepUpConfirmed();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(router.flushAll).toHaveBeenCalled();
        expect(calls).toHaveLength(2);
        expect(calls[1]?.href).toBe('/account/sessions/revoke-others');
        expect(calls[1]?.options.method).toBe('post');
        // The caller's own callbacks travel with the replay.
        expect(calls[1]?.options.onSuccess).toBe(onSuccess);
    });

    it('leaves every other refusal to the visit and Inertia', () => {
        const calls: { options: Record<string, unknown> }[] = [];
        const router = {
            visit: (_href: unknown, options: Record<string, unknown> = {}) => {
                calls.push({ options });
            },
            flushAll: vi.fn<() => void>(),
        };
        const own = vi.fn<() => undefined>();

        installStepUp(router as never);
        router.visit('/x', { onHttpException: own });

        const handler = calls[0]?.options.onHttpException as (response: unknown) => unknown;
        handler({ status: 500, headers: {}, data: 'boom' });

        expect(own).toHaveBeenCalled();
    });
});

describe('step-up by a trip to the login host', () => {
    const door = `${window.location.origin}/auth/start?step_up=environment`;
    const redirect = { page: door, popup: `${door}&popup=1` };

    it('reads the redirect a refusal carries, and only one on this origin', () => {
        const read = (value: unknown) =>
            challengeFrom(
                403,
                { 'x-cbox-step-up': door },
                body({ plane: 'environment', confirm: door, reason: null, redirect: value }),
            );

        expect(read(redirect)?.redirect).toEqual(redirect);
        expect(
            read({ page: 'https://login.example/x', popup: redirect.popup })?.redirect,
        ).toBeUndefined();
        expect(read(undefined)?.redirect).toBeUndefined();
    });

    it('opens the window, and resolves with what it reports for this plane', async () => {
        const { confirmStepUpByRedirect, STEP_UP_CHANNEL } = await import('./stepUp');
        const opened: string[] = [];
        const popup = { closed: false } as Window;

        const outcome = confirmStepUpByRedirect(
            { plane: 'environment', confirm: door, reason: null, redirect },
            (url) => {
                opened.push(url);

                return popup;
            },
        );

        const channel = new BroadcastChannel(STEP_UP_CHANNEL);
        channel.postMessage({ plane: 'organization', confirmed: true });
        channel.postMessage({ plane: 'environment', confirmed: true });

        await expect(outcome).resolves.toBe('confirmed');
        expect(opened).toEqual([redirect.popup]);
        channel.close();
    });

    it('does not take the window for closed when the login host isolates it', async () => {
        const { confirmStepUpByRedirect, STEP_UP_CHANNEL } = await import('./stepUp');
        vi.useFakeTimers();

        // What the opener sees once the window reaches a host that answers with
        // Cross-Origin-Opener-Policy: same-origin, although it is still open.
        const popup = { closed: true } as Window;
        const settled = vi.fn<() => void>();
        const outcome = confirmStepUpByRedirect(
            { plane: 'environment', confirm: door, reason: null, redirect },
            () => popup,
        );
        void outcome.then(settled);

        await vi.advanceTimersByTimeAsync(5_000);
        expect(settled).not.toHaveBeenCalled();
        vi.useRealTimers();

        const channel = new BroadcastChannel(STEP_UP_CHANNEL);
        channel.postMessage({ plane: 'environment', confirmed: true });

        await expect(outcome).resolves.toBe('confirmed');
        channel.close();
    });

    it('stops waiting when the caller gives up', async () => {
        const { confirmStepUpByRedirect } = await import('./stepUp');
        const wait = new AbortController();
        const outcome = confirmStepUpByRedirect(
            { plane: 'environment', confirm: door, reason: null, redirect },
            () => ({ closed: false }) as Window,
            wait.signal,
        );

        wait.abort();

        await expect(outcome).resolves.toBe('closed');
    });

    it('says so when the browser blocks the window, or there is nowhere to go', async () => {
        const { confirmStepUpByRedirect } = await import('./stepUp');

        await expect(
            confirmStepUpByRedirect(
                { plane: 'environment', confirm: door, reason: null, redirect },
                () => null,
            ),
        ).resolves.toBe('blocked');
        await expect(confirmStepUpByRedirect(organization, () => null)).resolves.toBe('blocked');
    });
});
