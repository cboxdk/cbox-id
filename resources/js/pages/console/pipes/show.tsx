import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Breadcrumb,
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    Input,
    Kv,
    KvList,
    PageHeader,
    Pagination,
    Panel,
    Pill,
    ProviderMark,
    Select,
    Switch,
    Textarea,
} from '@/ui';
import type { CatalogueEntry } from './create';

interface Grant {
    clientId: string;
    name: string | null;
    revokeHref: string;
}

interface Connection {
    id: string;
    userId: string;
    account: string | null;
    status: 'active' | 'needs_reauth';
    reauthReason: string | null;
    lastError: string | null;
    connectedAt: string | null;
    expiresAt: string | null;
    disconnectHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    pipe: {
        id: string;
        provider: string;
        name: string;
        clientId: string;
        scopes: string[];
        parameters: Record<string, string>;
        enabled: boolean;
        redirectUri: string;
        connectUrl: string;
        leaseUrl: string;
    };
    catalogue: CatalogueEntry | null;
    grants: Grant[];
    grantableApps: { clientId: string; name: string }[];
    connections: Connection[];
    pagination: PaginationState;
    indexHref: string;
    urls: { update: string; destroy: string; grant: string };
}>;

export default function PipeDetail({
    help,
    pipe,
    catalogue,
    grants,
    grantableApps,
    connections,
    pagination,
    indexHref,
    urls,
}: Props) {
    const [removing, setRemoving] = useState(false);

    return (
        <div className="space-y-6">
            <Breadcrumb href={indexHref} label="Pipes" />

            <PageHeader
                help={help}
                title={
                    <span className="inline-flex items-center gap-2.5">
                        <ProviderMark provider={pipe.provider} size={24} />
                        {pipe.name}
                    </span>
                }
                badge={
                    <Pill tone={pipe.enabled ? 'success' : 'neutral'}>
                        {pipe.enabled ? 'Enabled' : 'Disabled'}
                    </Pill>
                }
            />

            <Panel
                title="How your app uses it"
                description="Send people to the connect page; lease a token from your backend whenever you call the provider."
            >
                <KvList>
                    <Kv label="Connect page">
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.connectUrl}</span>
                            <CopyButton value={pipe.connectUrl} />
                        </span>
                    </Kv>
                    <Kv label="Lease endpoint">
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">POST {pipe.leaseUrl}</span>
                            <CopyButton value={pipe.leaseUrl} />
                        </span>
                    </Kv>
                    <Kv label="Redirect URI">
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.redirectUri}</span>
                            <CopyButton value={pipe.redirectUri} />
                        </span>
                    </Kv>
                    {catalogue !== null && <Kv label="API base URL">{catalogue.apiBaseUrl}</Kv>}
                </KvList>
            </Panel>

            <Settings pipe={pipe} catalogue={catalogue} href={urls.update} />

            <Grants grants={grants} apps={grantableApps} href={urls.grant} />

            <Panel
                title="Connected accounts"
                description="Who connected an account through this pipe. Tokens are never shown."
            >
                {connections.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        Nobody has connected an account yet.
                    </p>
                ) : (
                    <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                        {connections.map((connection) => (
                            <li
                                key={connection.id}
                                className="flex items-center justify-between gap-4 py-2.5"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="mono text-sm">{connection.userId}</span>
                                        {connection.account !== null && (
                                            <span
                                                className="text-sm"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                as {connection.account}
                                            </span>
                                        )}
                                        <Pill
                                            tone={
                                                connection.status === 'active'
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {connection.status === 'active'
                                                ? 'Active'
                                                : 'Needs reconnect'}
                                        </Pill>
                                    </div>
                                    <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                                        Connected {connection.connectedAt ?? '—'}
                                        {connection.expiresAt !== null &&
                                            ` · token expires ${connection.expiresAt}`}
                                        {connection.reauthReason !== null &&
                                            ` · ${connection.reauthReason}`}
                                        {connection.lastError !== null &&
                                            ` · last refresh failed: ${connection.lastError}`}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() =>
                                        router.delete(connection.disconnectHref, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Disconnect
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-3">
                    <Pagination
                        pagination={pagination}
                        noun="account"
                        href={(page) => `?page=${page}`}
                    />
                </div>
            </Panel>

            <Panel
                title="Remove pipe"
                description="Removes every connection through it. Tokens are revoked here, not at the provider — disconnect people first if that matters."
            >
                <Button size="sm" variant="danger" onClick={() => setRemoving(true)}>
                    Remove pipe
                </Button>
            </Panel>

            <ConfirmDelete
                open={removing}
                onOpenChange={setRemoving}
                name={pipe.name}
                verb="Remove"
                consequence="Every connected account through this pipe is forgotten and its tokens revoked here."
                onConfirm={() => {
                    setRemoving(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

function Settings({
    pipe,
    catalogue,
    href,
}: {
    pipe: Props['pipe'];
    catalogue: CatalogueEntry | null;
    href: string;
}) {
    const form = useForm({
        client_id: pipe.clientId,
        client_secret: '',
        scopes: pipe.scopes.join(' '),
        parameters: { ...pipe.parameters } as Record<string, string>,
        enabled: pipe.enabled,
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Panel
            title="Settings"
            description="People already connected keep the scopes they agreed to until they connect again."
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, {
                        preserveScroll: true,
                        onFinish: () => form.setData('client_secret', ''),
                    });
                }}
            >
                <Field
                    label="Enabled"
                    hint="A disabled pipe refuses new connections and every lease."
                >
                    <Switch
                        checked={form.data.enabled}
                        onCheckedChange={(checked) => form.setData('enabled', checked)}
                    />
                </Field>
                <Field label="Client ID" error={errors.client_id}>
                    <Input
                        className="mono"
                        value={form.data.client_id}
                        onChange={(event) => form.setData('client_id', event.target.value)}
                    />
                </Field>
                <Field
                    label="New client secret"
                    hint="Left empty, unchanged. The stored one is never shown."
                    error={errors.client_secret}
                >
                    <Input
                        type="password"
                        className="mono"
                        autoComplete="off"
                        value={form.data.client_secret}
                        onChange={(event) => form.setData('client_secret', event.target.value)}
                    />
                </Field>
                <Field label="Scopes" hint="Separated by spaces." error={errors.scopes}>
                    <Textarea
                        rows={2}
                        className="mono"
                        spellCheck={false}
                        value={form.data.scopes}
                        onChange={(event) => form.setData('scopes', event.target.value)}
                    />
                </Field>
                {catalogue?.parameters.map((parameter) => (
                    <Field
                        key={parameter.key}
                        label={parameter.label}
                        hint={parameter.help}
                        error={errors.parameters}
                    >
                        <Input
                            className="mono"
                            placeholder={parameter.default}
                            value={form.data.parameters[parameter.key] ?? ''}
                            onChange={(event) =>
                                form.setData('parameters', {
                                    ...form.data.parameters,
                                    [parameter.key]: event.target.value,
                                })
                            }
                        />
                    </Field>
                ))}
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    Save
                </Button>
            </form>
        </Panel>
    );
}

/**
 * Which apps may lease the tokens people connect through this pipe. Deny by default:
 * an app not listed here is refused, whatever its token's scopes say.
 */
function Grants({
    grants,
    apps,
    href,
}: {
    grants: Grant[];
    apps: { clientId: string; name: string }[];
    href: string;
}) {
    const form = useForm({ client_id: apps[0]?.clientId ?? '' });

    return (
        <Panel
            title="Apps that may lease tokens"
            description="Only the apps listed here get a token. Everything else is refused."
        >
            <div className="space-y-2">
                {grants.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        No app may lease tokens through this pipe yet.
                    </p>
                ) : (
                    <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                        {grants.map((grant) => (
                            <li
                                key={grant.clientId}
                                className="flex items-center justify-between gap-4 py-2"
                            >
                                <span>
                                    {grant.name !== null && (
                                        <span className="mr-2 font-medium">{grant.name}</span>
                                    )}
                                    <span
                                        className="mono text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {grant.clientId}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() =>
                                        router.delete(grant.revokeHref, { preserveScroll: true })
                                    }
                                >
                                    Revoke
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {apps.length > 0 && (
                <form
                    className="mt-4 flex items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, { preserveScroll: true });
                    }}
                >
                    <div className="flex-1">
                        <Field label="Grant an app" error={form.errors.client_id}>
                            <Select
                                value={form.data.client_id}
                                onValueChange={(value) => form.setData('client_id', value)}
                                options={apps.map((app) => ({
                                    value: app.clientId,
                                    label: app.name,
                                    hint: app.clientId,
                                }))}
                            />
                        </Field>
                    </div>
                    <Button type="submit" size="sm" loading={form.processing}>
                        Grant
                    </Button>
                </form>
            )}
        </Panel>
    );
}

PipeDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
