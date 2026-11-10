import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [removing, setRemoving] = useState(false);

    return (
        <div className="space-y-6">
            <Breadcrumb href={indexHref} label={consoleT('console.pipes_685afe34')} />

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
                            ? consoleText('console.enabled_92c1cdfd')
                            : consoleText('console.disabled_75081b59')}
                    </Pill>
                }
            />

            <Panel
                title={consoleT('console.how_your_app_uses_it_bde1452e')}
                description={consoleT(
                    'console.send_people_to_the_connect_page_lease_a_token_from_your_db119651',
                )}
            >
                <KvList>
                    <Kv label={consoleT('console.connect_page_b46eaa6a')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.connectUrl}</span>
                            <CopyButton value={pipe.connectUrl} />
                        </span>
                    </Kv>
                    <Kv label={consoleT('console.lease_endpoint_320ca77d')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">
                                {consoleRich('console.post_part1_aa0c2484', {
                                    part1: pipe.leaseUrl,
                                })}
                            </span>
                            <CopyButton value={pipe.leaseUrl} />
                        </span>
                    </Kv>
                    <Kv label={consoleT('console.redirect_uri_98e548b4')}>
                        <span className="inline-flex items-center gap-2">
                            <span className="mono break-all">{pipe.redirectUri}</span>
                            <CopyButton value={pipe.redirectUri} />
                        </span>
                    </Kv>
                    {catalogue !== null && (
                        <Kv label={consoleT('console.api_base_url_a45474ea')}>
                            {catalogue.apiBaseUrl}
                        </Kv>
                    )}
                </KvList>
            </Panel>

            <Settings pipe={pipe} catalogue={catalogue} href={urls.update} />

            <Grants grants={grants} apps={grantableApps} href={urls.grant} />

            <Panel
                title={consoleT('console.connected_accounts_2fc385cd')}
                description={consoleT(
                    'console.who_connected_an_account_through_this_pipe_tokens_are_n_ea456475',
                )}
            >
                {connections.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {consoleT('console.nobody_has_connected_an_account_yet_dd232d26')}
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
                                                {consoleRich('console.as_part1_cc84f04f', {
                                                    part1: connection.account,
                                                })}
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
                                                ? consoleText('console.active_92340695')
                                                : consoleText('console.needs_reconnect_62fe6ecf')}
                                        </Pill>
                                    </div>
                                    <p className="mt-0.5 text-xs" style={{ color: 'var(--faint)' }}>
                                        {consoleRich(
                                            'console.connected_part1_part2_part3_part4_a093c4a3',
                                            {
                                                part1: connection.connectedAt ?? '—',
                                                part2:
                                                    connection.expiresAt !== null &&
                                                    consoleNestedText(
                                                        'console._token_expires_part1_6d6d489c',
                                                        { part1: connection.expiresAt },
                                                    ),
                                                part3:
                                                    connection.reauthReason !== null &&
                                                    ` · ${connection.reauthReason}`,
                                                part4:
                                                    connection.lastError !== null &&
                                                    consoleNestedText(
                                                        'console._last_refresh_failed_part1_5c8d4687',
                                                        { part1: connection.lastError },
                                                    ),
                                            },
                                        )}
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
                                    {consoleT('console.disconnect_acfc5be7')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="mt-3">
                    <Pagination pagination={pagination} href={(page) => `?page=${page}`} />
                </div>
            </Panel>

            <Panel
                title={consoleT('console.remove_pipe_a4c284c2')}
                description={consoleT(
                    'console.removes_every_connection_through_it_tokens_are_revoked_eec0a79e',
                )}
            >
                <Button size="sm" variant="danger" onClick={() => setRemoving(true)}>
                    {consoleT('console.remove_pipe_a4c284c2')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={removing}
                onOpenChange={setRemoving}
                name={pipe.name}
                verb={consoleShortText('console.remove_c3812fc4')}
                consequence={consoleUiText(
                    'console.every_connected_account_through_this_pipe_is_forgotten_9181a044',
                )}
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
    const { t: consoleT } = useConsoleTranslator();
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
            title={consoleT('console.settings_74a883a0')}
            description={consoleT(
                'console.people_already_connected_keep_the_scopes_they_agreed_to_f979f289',
            )}
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
                    label={consoleT('console.enabled_92c1cdfd')}
                    hint={consoleT(
                        'console.a_disabled_pipe_refuses_new_connections_and_every_lease_4b6ed7e3',
                    )}
                >
                    <Switch
                        checked={form.data.enabled}
                        onCheckedChange={(checked) => form.setData('enabled', checked)}
                    />
                </Field>
                <Field label={consoleT('console.client_id_8726db01')} error={errors.client_id}>
                    <Input
                        className="mono"
                        value={form.data.client_id}
                        onChange={(event) => form.setData('client_id', event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.new_client_secret_02aac1d8')}
                    hint={consoleT(
                        'console.left_empty_unchanged_the_stored_one_is_never_shown_46d6d236',
                    )}
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
                    label={consoleT('console.scopes_0d5644ff')}
                    hint={consoleT('console.separated_by_spaces_19d6b908')}
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
                    {consoleT('console.save_1509f561')}
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
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ client_id: apps[0]?.clientId ?? '' });

    return (
        <Panel
            title={consoleT('console.apps_that_may_lease_tokens_683bd8d1')}
            description={consoleT(
                'console.only_the_apps_listed_here_get_a_token_everything_else_i_3d600e70',
            )}
        >
            <div className="space-y-2">
                {grants.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {consoleT('console.no_app_may_lease_tokens_through_this_pipe_yet_67e304c0')}
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
                                    {consoleT('console.revoke_87e6d00b')}
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
                            label={consoleT('console.grant_an_app_a437ce1b')}
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
                        {consoleT('console.grant_78b7d037')}
                    </Button>
                </form>
            )}
        </Panel>
    );
}

PipeDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
