import type { Channel } from 'pusher-js';
import type Pusher from 'pusher-js';
import type { RealtimeConfig } from '@/types';

/**
 * THE LIVE-UPDATE CONNECTION — one socket per tab, shared by every page that listens.
 *
 * What arrives is an invalidation signal, never data: `{ topics, changes: [{ topic, type,
 * id, action }] }`. A page that cares re-reads itself through the endpoints and gates it
 * was drawn through ({@see useLiveReload}). So nothing here is trusted with anything: a
 * forged or replayed message can at most cause a reload the server then authorizes.
 *
 * Optional by construction. No config (Reverb off, nobody signed in) means no socket;
 * a socket that cannot connect retries with pusher-js's own backoff while the page keeps
 * working exactly as it did before live updates existed. Nothing is shown about it.
 *
 * pusher-js is loaded lazily, on the first page that has a channel, so the sign-in pages
 * and every page with live updates off never download it.
 */

export interface LiveChange {
    topic: string;
    type: string;
    id: string | null;
    action: string;
}

export interface LiveMessage {
    topics: string[];
    changes: LiveChange[];
    truncated: boolean;
}

type Listener = (message: LiveMessage) => void;

interface Subscription {
    channel: Channel | null;
    listeners: Set<Listener>;
}

const EVENT = 'changed';

let client: Pusher | null = null;
let clientKey: string | null = null;
let loading: Promise<Pusher | null> | null = null;
let authUrl: string | null = null;
const subscriptions = new Map<string, Subscription>();

function signature(config: RealtimeConfig): string {
    return [config.key, config.host ?? '', config.port, config.scheme].join('|');
}

/** Read a signal defensively: anything malformed is dropped, not half-applied. */
export function parseMessage(data: unknown): LiveMessage | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const record = data as Record<string, unknown>;
    const topics = Array.isArray(record.topics)
        ? record.topics.filter((topic): topic is string => typeof topic === 'string')
        : [];

    if (topics.length === 0) {
        return null;
    }

    const changes = Array.isArray(record.changes)
        ? record.changes.filter(
              (change): change is LiveChange =>
                  typeof change === 'object' &&
                  change !== null &&
                  typeof (change as LiveChange).topic === 'string' &&
                  typeof (change as LiveChange).type === 'string' &&
                  typeof (change as LiveChange).action === 'string',
          )
        : [];

    return { topics, changes, truncated: record.truncated === true };
}

async function connect(config: RealtimeConfig): Promise<Pusher | null> {
    // The page that asks next decides which endpoint signs: each console mounts its own,
    // inside its own gates.
    authUrl = config.authUrl;

    if (client !== null && clientKey === signature(config)) {
        return client;
    }

    if (loading !== null && clientKey === signature(config)) {
        return loading;
    }

    // A different deployment key: the old socket goes; the channels asked for stay asked
    // for and are subscribed on the new one.
    closeClient();
    clientKey = signature(config);

    loading = import('pusher-js')
        .then(({ default: PusherClient }) => {
            const secure = config.scheme === 'https';
            const host = config.host ?? window.location.hostname;

            const instance = new PusherClient(config.key, {
                cluster: '',
                wsHost: host,
                wsPort: secure ? 443 : config.port,
                wssPort: secure ? config.port : 443,
                forceTLS: secure,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                channelAuthorization: {
                    endpoint: '',
                    transport: 'ajax',
                    customHandler: (params, callback) => {
                        void authorize(params.socketId, params.channelName).then(
                            (auth) => callback(null, auth),
                            (error: unknown) =>
                                callback(
                                    error instanceof Error ? error : new Error(String(error)),
                                    null,
                                ),
                        );
                    },
                },
            });

            client = instance;

            return instance;
        })
        .catch(() => null)
        .finally(() => {
            loading = null;
        });

    return loading;
}

/**
 * A channel signature from the page's own host. A GET with the session cookie: the
 * endpoint sits behind the same gates as the page, which is the whole authorization.
 */
async function authorize(socketId: string, channelName: string): Promise<{ auth: string }> {
    if (authUrl === null) {
        throw new Error('No live-update authorization endpoint on this page.');
    }

    const url = new URL(authUrl, window.location.origin);
    url.searchParams.set('socket_id', socketId);
    url.searchParams.set('channel_name', channelName);

    const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`Live updates refused (${response.status}).`);
    }

    const body = (await response.json()) as { auth?: unknown };

    if (typeof body.auth !== 'string') {
        throw new Error('Live updates: malformed authorization.');
    }

    return { auth: body.auth };
}

/**
 * Listen on one channel. Returns the function that stops listening; the channel is left
 * when its last listener goes, so a page change re-authorizes what the next page uses.
 */
export function listen(config: RealtimeConfig, channel: string, listener: Listener): () => void {
    const name = `private-${channel}`;
    const existing = subscriptions.get(name);
    const subscription: Subscription = existing ?? { channel: null, listeners: new Set() };
    subscription.listeners.add(listener);
    subscriptions.set(name, subscription);

    if (existing === undefined) {
        void connect(config).then((pusher) => {
            // Gone already: every listener left before the socket was up.
            if (pusher === null || subscriptions.get(name) !== subscription) {
                return;
            }

            const bound = pusher.subscribe(name);
            bound.bind(EVENT, (data: unknown) => {
                const message = parseMessage(data);

                if (message !== null) {
                    subscription.listeners.forEach((each) => each(message));
                }
            });
            subscription.channel = bound;
        });
    }

    return () => {
        subscription.listeners.delete(listener);

        if (subscription.listeners.size > 0) {
            return;
        }

        subscriptions.delete(name);

        if (subscription.channel !== null && client !== null) {
            subscription.channel.unbind(EVENT);
            client.unsubscribe(name);
        }
    };
}

function closeClient(): void {
    client?.disconnect();
    client = null;
    clientKey = null;
}

/** Close the socket and forget every channel. */
export function disconnect(): void {
    subscriptions.clear();
    closeClient();
}
