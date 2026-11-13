import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    defaultPasskeyName,
    isCancellation,
    LeavingPage,
    navigation,
    signInWithPasskey,
} from './passkeys';

describe('defaultPasskeyName', () => {
    it.each([
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'Passkey on Chrome · macOS',
        ],
        [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
            'Passkey on Safari · macOS',
        ],
        [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            'Passkey on Safari · iPhone',
        ],
        [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
            'Passkey on Edge · Windows',
        ],
        [
            'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
            'Passkey on Firefox · Linux',
        ],
        [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
            'Passkey on Chrome · Android',
        ],
        ['curl/8.0', 'Passkey'],
    ])('names %s', (agent, name) => {
        expect(defaultPasskeyName(agent)).toBe(name);
    });
});

/*
 * THE SIGN-IN PAGE OF A BROWSER THAT SIGNED IN SINCE (Chrome on an iPhone, a restored tab).
 * The options request was answered with a 302 to the app host; the fetch followed it, CSP
 * (`connect-src 'self'`) refused the hop, and the page could only say "Load failed".
 */
describe('signInWithPasskey when the page is out of date', () => {
    const get = vi.fn<CredentialsContainer['get']>();

    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf-fixture">';
        vi.stubGlobal('navigator', { ...navigator, credentials: { get } });
        vi.spyOn(navigation, 'assign').mockImplementation(() => undefined);
        vi.spyOn(navigation, 'reload').mockImplementation(() => undefined);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        get.mockReset();
        document.head.innerHTML = '';
    });

    it('never lets the fetch follow a redirect, and asks for JSON', async () => {
        const fetcher = vi
            .fn<typeof fetch>()
            .mockResolvedValue(Response.json({ redirect: '/dashboard' }, { status: 409 }));
        vi.stubGlobal('fetch', fetcher);

        await signInWithPasskey().catch(() => undefined);

        const options = fetcher.mock.calls[0]?.[1];
        expect(options?.redirect).toBe('manual');
        expect(options?.credentials).toBe('same-origin');
        expect(new Headers(options?.headers).get('Accept')).toBe('application/json');
        expect(new Headers(options?.headers).get('X-CSRF-TOKEN')).toBe('csrf-fixture');
    });

    it('goes where the server says when the browser is already signed in, without a ceremony', async () => {
        const target = 'https://app.cboxid.test/auth/start?return_to=%2Fdashboard';
        vi.stubGlobal(
            'fetch',
            vi
                .fn<typeof fetch>()
                .mockResolvedValue(
                    Response.json(
                        { message: 'You are already signed in.', redirect: target },
                        { status: 409 },
                    ),
                ),
        );

        const error: unknown = await signInWithPasskey().catch((caught: unknown) => caught);

        expect(error).toBeInstanceOf(LeavingPage);
        // Nothing to report under the button: the page is on its way.
        expect(isCancellation(error)).toBe(true);
        expect(navigation.assign).toHaveBeenCalledWith(target);
        expect(get).not.toHaveBeenCalled();
    });

    it('loads the page afresh when the server answers with a redirect anyway', async () => {
        // What `redirect: 'manual'` hands back for a 3xx: an opaque response, status 0.
        const opaque = { type: 'opaqueredirect', ok: false, status: 0 } as Response;
        vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(opaque));

        await expect(signInWithPasskey()).rejects.toBeInstanceOf(LeavingPage);
        expect(navigation.reload).toHaveBeenCalledOnce();
        expect(get).not.toHaveBeenCalled();
    });

    it('loads the page afresh when its session and CSRF token have expired', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn<typeof fetch>()
                .mockResolvedValue(
                    Response.json({ message: 'CSRF token mismatch.' }, { status: 419 }),
                ),
        );

        await expect(signInWithPasskey()).rejects.toBeInstanceOf(LeavingPage);
        expect(navigation.reload).toHaveBeenCalledOnce();
    });

    it('still reports a refusal it cannot act on', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn<typeof fetch>()
                .mockResolvedValue(
                    Response.json({ error: 'Passkeys are off here.' }, { status: 403 }),
                ),
        );

        await expect(signInWithPasskey()).rejects.toThrow('Passkeys are off here.');
        expect(navigation.assign).not.toHaveBeenCalled();
        expect(navigation.reload).not.toHaveBeenCalled();
    });
});
