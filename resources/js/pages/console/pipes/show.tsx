import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    const [removing, setRemoving] = useState(false);

    return (
        <div className="space-y-6">
            <Breadcrumb href={indexHref} label={tc('console.pipes_685afe34')} />

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
                        {pipe.enabled
                            ? tc('console.enabled_92c1cdfd')
                            : tc('console.disabled_75081b59')}
                    </Pill>
                }
            />

            <Panel
                title={tc('console.how_your_app_uses_it_bde1452e')}
                description={tc('console.send_people_to_the_connect_page_lease_a_toke_db119651')}
            >
                <KvList>
                    <Kv label={tc('console.connect_page_b46eaa6a')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.connectUrl}</span>
                            <CopyButton value={pipe.connectUrl} />
                        </span>
                    </Kv>
                    <Kv label={tc('console.lease_endpoint_320ca77d')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">POST {pipe.leaseUrl}</span>
                            <CopyButton value={pipe.leaseUrl} />
                        </span>
                    </Kv>
                    <Kv label={tc('console.redirect_uri_98e548b4')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.redirectUri}</span>
                            <CopyButton value={pipe.redirectUri} />
                        </span>
                    </Kv>
                    {catalogue !== null && (
                        <Kv label={tc('console.api_base_url_a45474ea')}>{catalogue.apiBaseUrl}</Kv>
                    )}
                </KvList>
            </Panel>

            <Settings pipe={pipe} catalogue={catalogue} href={urls.update} />

            <Grants grants={grants} apps={grantableApps} href={urls.grant} />

            <Panel
                title={tc('console.connected_accounts_2fc385cd')}
                description={tc('console.who_connected_an_account_through_this_pipe_t_ea456475')}
            >
                {connections.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {tc('console.nobody_has_connected_an_account_yet_dd232d26')}
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
                                                {tc('console.as_12876f7c')}
                                                {connection.account}
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
                                                ? tc('console.active_92340695')
                                                : tc('console.needs_reconnect_62fe6ecf')}
                                        </Pill>
                                    </div>
                                    <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                                        {tc('console.connected_a09c65fb')}
                                        {connection.connectedAt ?? '—'}
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
                                    {tc('console.disconnect_acfc5be7')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-3">
                    <Pagination
                        pagination={pagination}
                        noun={tc('console.account_9af21132')}
                        pluralNoun={tc('console.accounts_bc62a3c1')}
                        href={(page) => `?page=${page}`}
                    />
                </div>
            </Panel>

            <Panel
                title={tc('console.remove_pipe_a4c284c2')}
                description={tc('console.removes_every_connection_through_it_tokens_a_eec0a79e')}
            >
                <Button size="sm" variant="danger" onClick={() => setRemoving(true)}>
                    {tc('console.remove_pipe_a4c284c2')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={removing}
                onOpenChange={setRemoving}
                name={pipe.name}
                verb="Remove"
                consequence={tc('console.every_connected_account_through_this_pipe_is_9181a044')}
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
    const { t: tc } = useTranslator();

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
            title={tc('console.settings_74a883a0')}
            description={tc('console.people_already_connected_keep_the_scopes_the_f979f289')}
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
                    label={tc('console.enabled_92c1cdfd')}
                    hint={tc('console.a_disabled_pipe_refuses_new_connections_and__4b6ed7e3')}
                >
                    <Switch
                        checked={form.data.enabled}
                        onCheckedChange={(checked) => form.setData('enabled', checked)}
                    />
                </Field>
                <Field label={tc('console.client_id_8726db01')} error={errors.client_id}>
                    <Input
                        className="mono"
                        value={form.data.client_id}
                        onChange={(event) => form.setData('client_id', event.target.value)}
                    />
                </Field>
                <Field
                    label={tc('console.new_client_secret_02aac1d8')}
                    hint={tc('console.left_empty_unchanged_the_stored_one_is_never_46d6d236')}
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
                <Field
                    label={tc('console.scopes_0d5644ff')}
                    hint={tc('console.separated_by_spaces_19d6b908')}
                    error={errors.scopes}
                >
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
                    {tc('console.save_1509f561')}
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
    const { t: tc } = useTranslator();

    const form = useForm({ client_id: apps[0]?.clientId ?? '' });

    return (
        <Panel
            title={tc('console.apps_that_may_lease_tokens_683bd8d1')}
            description={tc('console.only_the_apps_listed_here_get_a_token_everyt_3d600e70')}
        >
            <div className="space-y-2">
                {grants.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {tc('console.no_app_may_lease_tokens_through_this_pipe_ye_67e304c0')}
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
                                    {tc('console.revoke_87e6d00b')}
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
                        <Field
                            label={tc('console.grant_an_app_a437ce1b')}
                            error={form.errors.client_id}
                        >
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
                        {tc('console.grant_78b7d037')}
                    </Button>
                </form>
            )}
        </Panel>
    );
}

PipeDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
