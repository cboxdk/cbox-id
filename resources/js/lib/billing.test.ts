import { afterEach, describe, expect, it, vi } from 'vitest';
import { billingMoney, billingRequest } from './billing';

afterEach(() => {
    vi.unstubAllGlobals();
    document.head.innerHTML = '';
});
describe('billing amounts', () => {
    it('uses the currency exponent instead of assuming cents', () => {
        expect(billingMoney(1234, 'USD', 'en-US')).toBe('$12.34');
        expect(billingMoney(1234, 'JPY', 'en-US')).toBe('¥1,234');
        expect(billingMoney(1234, 'KWD', 'en-US')).toContain('1.234');
    });
    it('keeps zero and negative invoice amounts', () => {
        expect(billingMoney(0, 'USD', 'en-US')).toBe('$0.00');
        expect(billingMoney(-500, 'USD', 'en-US')).toBe('-$5.00');
    });
});
describe('billing requests', () => {
    it('binds every request to the rendered organization, overriding caller data', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf-fixture">';
        const fetcher = vi.fn<typeof fetch>().mockResolvedValue(Response.json({ status: 'saved' }));
        vi.stubGlobal('fetch', fetcher);
        await expect(
            billingRequest('/billing/payment-methods/complete', 'org-A', {
                organization: 'org-B',
                payment_method: 'pm-1',
            }),
        ).resolves.toEqual({ status: 'saved' });
        const options = fetcher.mock.calls[0]?.[1];
        if (!options) throw new Error('Missing request');
        expect(JSON.parse(String(options.body))).toEqual({
            organization: 'org-A',
            payment_method: 'pm-1',
        });
        expect(options.credentials).toBe('same-origin');
        expect(new Headers(options.headers).get('X-CSRF-TOKEN')).toBe('csrf-fixture');
    });
    it('does not expose provider errors or accept failed responses', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn<typeof fetch>()
                .mockResolvedValue(new Response('secret provider error', { status: 409 })),
        );
        await expect(billingRequest('/billing/payment-methods/complete', 'org-A')).rejects.toThrow(
            '409',
        );
    });
});
