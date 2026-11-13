import { afterEach, describe, expect, it, vi } from 'vitest';
import type { RealtimeConfig } from '@/types';

const subscribed: string[] = [];
const unsubscribed: string[] = [];

vi.mock('pusher-js', () => ({
    default: class {
        constructor(
            public key: string,
            public options: Record<string, unknown>,
        ) {}
        subscribe(name: string) {
            subscribed.push(name);

            return { bind: vi.fn<() => void>(), unbind: vi.fn<() => void>() };
        }
        unsubscribe(name: string) {
            unsubscribed.push(name);
        }
        disconnect() {}
    },
}));

const config: RealtimeConfig = {
    key: 'key',
    host: null,
    port: 443,
    scheme: 'https',
    authUrl: '/realtime/auth',
    channels: ['id.subject.a', 'id.organization.b'],
};

describe('listen', () => {
    afterEach(async () => {
        (await import('./realtime')).disconnect();
        subscribed.length = 0;
        unsubscribed.length = 0;
    });

    it('subscribes every channel over one connection, and leaves each with its last listener', async () => {
        const { listen } = await import('./realtime');

        const stopA = listen(config, 'id.subject.a', () => undefined);
        const stopB = listen(config, 'id.organization.b', () => undefined);
        await vi.waitFor(() =>
            expect(subscribed).toEqual(['private-id.subject.a', 'private-id.organization.b']),
        );

        stopA();
        stopB();
        expect(unsubscribed).toEqual(['private-id.subject.a', 'private-id.organization.b']);
    });
});

describe('listen, shared', () => {
    afterEach(async () => {
        (await import('./realtime')).disconnect();
        subscribed.length = 0;
        unsubscribed.length = 0;
    });

    it('keeps a channel another listener still wants, even if the first one left before it connected', async () => {
        const { listen } = await import('./realtime');

        const first = listen(config, 'id.subject.a', () => undefined);
        const second = listen(config, 'id.subject.a', () => undefined);
        first();
        await vi.waitFor(() => expect(subscribed).toEqual(['private-id.subject.a']));

        second();
        expect(unsubscribed).toEqual(['private-id.subject.a']);
    });
});
