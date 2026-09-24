import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Input, Panel, Switch } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

type Props = PageProps<{
    appHeader: AppHeaderData;
    lifetime: {
        /** Null: the install's default. */
        minutes: number | null;
        defaultMinutes: number;
        ceilingMinutes: number;
        href: string;
    };
    exchange: { enabled: boolean; available: boolean; href: string };
    logout: { uri: string; sessionRequired: boolean; href: string };
    apiKeys: { prefix: string; href: string };
}>;

/**
 * FOUR FORMS, each saving one setting — so saving one never writes back a value another
 * form changed since the page loaded, and each change is one line on the activity log.
 */
export default function ClientSettings({ appHeader, lifetime, exchange, logout, apiKeys }: Props) {
    return (
        <AppFrame app={appHeader}>
            <LifetimeForm lifetime={lifetime} />
            <ExchangeForm exchange={exchange} />
            <LogoutForm logout={logout} />
            <ApiKeysForm apiKeys={apiKeys} />
        </AppFrame>
    );
}

function LifetimeForm({ lifetime }: { lifetime: Props['lifetime'] }) {
    const form = useForm({ minutes: lifetime.minutes === null ? '' : String(lifetime.minutes) });

    return (
        <Panel
            title="Access token lifetime"
            description="How long an access token for this app is accepted. Shorter is safer — a token cannot be taken back once issued — and refresh tokens keep people signed in in the meantime."
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(lifetime.href, { preserveScroll: true });
                }}
            >
                <Field
                    label="Minutes"
                    optional
                    hint={`Leave empty for this install's default, ${lifetime.defaultMinutes} minutes. At most ${lifetime.ceilingMinutes} minutes — the ceiling whoever runs this install has set.`}
                    error={form.errors.minutes}
                >
                    <Input
                        name="minutes"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={lifetime.ceilingMinutes}
                        placeholder={String(lifetime.defaultMinutes)}
                        style={{ maxWidth: '12rem' }}
                        value={form.data.minutes}
                        onChange={(event) => form.setData('minutes', event.target.value)}
                    />
                </Field>
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    Save lifetime
                </Button>
            </form>
        </Panel>
    );
}

function ExchangeForm({ exchange }: { exchange: Props['exchange'] }) {
    const form = useForm({ enabled: exchange.enabled });

    return (
        <Panel
            title="Token exchange"
            description="Lets this app trade a token it was given for one meant for another of your services, narrower or for a different audience, over the standard token-exchange grant (RFC 8693). Turn it on for a backend that calls further services on a person's behalf."
        >
            {exchange.available ? (
                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(exchange.href, { preserveScroll: true });
                    }}
                >
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            name="enabled"
                            checked={form.data.enabled}
                            onCheckedChange={(checked) => form.setData('enabled', checked)}
                        />
                        {form.data.enabled ? 'On' : 'Off'}
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.enabled === exchange.enabled}
                    >
                        Save
                    </Button>
                    {form.errors.enabled !== undefined && (
                        <p
                            className="w-full text-sm"
                            style={{ color: 'var(--destructive-strong)' }}
                        >
                            {form.errors.enabled}
                        </p>
                    )}
                </form>
            ) : (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Not available for this app. Only an app that holds a secret or its own keys can
                    exchange tokens — a public app cannot prove it is the one asking.
                </p>
            )}
        </Panel>
    );
}

function LogoutForm({ logout }: { logout: Props['logout'] }) {
    const form = useForm({ uri: logout.uri, sessionRequired: logout.sessionRequired });

    return (
        <Panel
            title="Back-channel logout"
            description="When somebody signs out — or an administrator ends their sessions, removes them or deactivates them — Cbox ID posts a signed logout token to this address, server to server, so the app can end its own session too."
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(logout.href, { preserveScroll: true });
                }}
            >
                <Field
                    label="Logout URI"
                    optional
                    hint="HTTPS, or HTTP on localhost. Leave empty and the app is not told."
                    error={form.errors.uri}
                >
                    <Input
                        name="uri"
                        type="url"
                        className="mono"
                        spellCheck={false}
                        placeholder="https://app.example.com/auth/backchannel-logout"
                        value={form.data.uri}
                        onChange={(event) => form.setData('uri', event.target.value)}
                    />
                </Field>
                <Checkbox
                    checked={form.data.sessionRequired}
                    disabled={form.data.uri.trim() === ''}
                    onCheckedChange={(checked) => form.setData('sessionRequired', checked)}
                    label="The app needs the session id"
                    hint="Every logout token then names the session (sid) as well as the person, so the app can end that one session rather than all of theirs."
                />
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    Save logout
                </Button>
            </form>
        </Panel>
    );
}

function ApiKeysForm({ apiKeys }: { apiKeys: Props['apiKeys'] }) {
    const form = useForm({ prefix: apiKeys.prefix });
    const example = form.data.prefix.trim() === '' ? 'acme_live' : form.data.prefix.trim();

    return (
        <Panel
            title="API keys"
            description="Lets the people who use this app create API keys for its API, under My account › API keys — each tied to them, one organization and a subset of their permissions in this app. Your API checks a key by calling Cbox ID with this app's own credentials, and a key loses a permission the moment its holder does."
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(apiKeys.href, { preserveScroll: true });
                }}
            >
                <Field
                    label="Key prefix"
                    optional
                    hint={
                        <>
                            Keys start with it, so a key found in a log names this app:{' '}
                            <code className="mono">{example}_…</code>. 2–16 lowercase letters or
                            digits, then <code className="mono">_live</code> or{' '}
                            <code className="mono">_test</code>, unique in this environment. Setting
                            one turns keys on; clearing it stops new keys, and keys already created
                            keep working until they are revoked.
                        </>
                    }
                    error={form.errors.prefix}
                >
                    <Input
                        name="prefix"
                        className="mono"
                        spellCheck={false}
                        autoCapitalize="off"
                        placeholder="acme_live"
                        style={{ maxWidth: '16rem' }}
                        value={form.data.prefix}
                        onChange={(event) => form.setData('prefix', event.target.value)}
                    />
                </Field>
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    Save prefix
                </Button>
            </form>
        </Panel>
    );
}

ClientSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
