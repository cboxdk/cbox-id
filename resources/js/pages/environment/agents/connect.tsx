import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import {
    cboxLogin,
    claudeCode,
    claudeCodeOAuth,
    claudeCodeWorkspace,
    claudeDesktop,
    type ClientId,
    type ClientSnippet,
    cursor,
    generic,
    sharedProjectConfig,
    vscode,
} from '@/lib/mcpSnippets';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, CopyButton, Icon, PageHeader, Panel, Tab, TabPanel, Tabs } from '@/ui';

type Props = PageProps<{
    mcpUrl: string;
    metadataUrl: string;
    restBaseUrl: string;
    openApiUrl: string;
    /** True once the MCP resource lets a client sign a person in instead of holding a key. */
    oauthAvailable: boolean;
    /** The platform root's `/mcp` — one connection for the whole workspace — when there is one. */
    workspace: WorkspaceProps | null;
    urls: { createAgent: string; agents: string };
    help: HelpContent;
}>;

interface WorkspaceProps {
    mcpUrl: string;
    issuer: string;
    restBaseUrl: string;
    /** True while an MCP client may sign a person in at the root itself (`api.mcp.root_oauth`). */
    oauth: boolean;
    /** Keys › Workspace keys on the workspace's host; null on a single-host deployment. */
    workspaceKeysHref: string | null;
}

const CLIENTS: { id: ClientId; label: string }[] = [
    { id: 'claude-code', label: 'Claude Code' },
    { id: 'claude-desktop', label: 'Claude Desktop' },
    { id: 'cursor', label: 'Cursor' },
    { id: 'vscode', label: 'VS Code' },
    { id: 'generic', label: 'Generic' },
];

export default function ConnectAgent({
    mcpUrl,
    metadataUrl,
    restBaseUrl,
    openApiUrl,
    oauthAvailable,
    workspace,
    urls,
    help,
}: Props) {
    const { t: tc } = useTranslator();

    const [client, setClient] = useState<ClientId>('claude-code');

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.this_environment_serves_an_mcp_server_next_t_8094252a')}
                actions={
                    <Button asChild size="sm" variant="primary" icon="plus">
                        <Link href={urls.createAgent}>
                            {tc('console.create_a_key_for_this_agent_f16f18d7')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-6" style={{ maxWidth: '52rem' }}>
                <Panel title={tc('console.mcp_server_d938c816')}>
                    <div className="flex items-center gap-2">
                        <code
                            className="mono text-sm break-all flex-1 rounded-lg px-3 py-2.5"
                            style={{ background: 'var(--surface-2)' }}
                        >
                            {mcpUrl}
                        </code>
                        <CopyButton value={mcpUrl} label={tc('console.copy_url_b26d1037')} />
                    </div>
                    <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.streamable_http_every_request_carries_006a13fe')}{' '}
                        <span className="mono">
                            {tc('console.authorization_bearer_cbid_env_4fde5a9b')}
                        </span>
                        {tc('console.a_management_key_of_this_environment_fbaf8743')}
                    </p>
                </Panel>

                <Panel title={tc('console.set_up_your_client_facf6955')}>
                    <Tabs
                        value={client}
                        onValueChange={(next) => setClient(next as ClientId)}
                        label={tc('console.mcp_client_c6f98ebe')}
                        panels={
                            <div className="pt-4">
                                <TabPanel value="claude-code">
                                    <div className="space-y-5">
                                        <Snippet snippet={claudeCode(mcpUrl)} />
                                        <div>
                                            <p className="text-sm font-medium">
                                                {tc('console.share_it_with_your_team_474083cd')}
                                            </p>
                                            <p
                                                className="text-xs mt-0.5"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {tc(
                                                    'console.commit_this_file_and_give_each_person_their__f23e11a2',
                                                )}
                                            </p>
                                            <div className="mt-2">
                                                <Snippet snippet={sharedProjectConfig(mcpUrl)} />
                                            </div>
                                        </div>
                                        <SignInOption available={oauthAvailable} mcpUrl={mcpUrl} />
                                    </div>
                                </TabPanel>
                                <TabPanel value="claude-desktop">
                                    <Snippet snippet={claudeDesktop(mcpUrl)} />
                                </TabPanel>
                                <TabPanel value="cursor">
                                    <Snippet snippet={cursor(mcpUrl)} />
                                </TabPanel>
                                <TabPanel value="vscode">
                                    <div className="space-y-2">
                                        <Snippet snippet={vscode(mcpUrl)} />
                                        <p
                                            className="text-xs"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {tc(
                                                'console.vs_code_asks_for_the_key_the_first_time_and__e11c90d5',
                                            )}
                                        </p>
                                    </div>
                                </TabPanel>
                                <TabPanel value="generic">
                                    <Snippet
                                        snippet={generic({
                                            mcpUrl,
                                            metadataUrl,
                                            restBaseUrl,
                                            openApiUrl,
                                        })}
                                    />
                                </TabPanel>
                            </div>
                        }
                    >
                        {CLIENTS.map((option) => (
                            <Tab key={option.id} value={option.id}>
                                {option.label}
                            </Tab>
                        ))}
                    </Tabs>
                </Panel>

                {workspace && <WorkspaceConnection workspace={workspace} />}

                <Panel title={tc('console.for_developers_6f79cf5c')}>
                    <dl className="grid gap-3 text-sm">
                        <Endpoint
                            label={tc('console.resource_metadata_rfc_9728_54cff8ab')}
                            value={metadataUrl}
                        />
                        <Endpoint label="REST API" value={restBaseUrl} />
                        <Endpoint
                            label={tc('console.openapi_cf2c9e21')}
                            value={openApiUrl}
                            external
                        />
                    </dl>
                </Panel>

                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.already_have_a_key_b399339b')}{' '}
                    <Link href={urls.agents} style={{ color: 'var(--primary)' }}>
                        {tc('console.see_every_agent_and_what_it_can_do_d10856a4')}
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}

function Snippet({ snippet }: { snippet: ClientSnippet }) {
    const { t: tc } = useTranslator();

    return (
        <div>
            <p className="label">{snippet.where}</p>
            <div className="mt-1.5 relative">
                <section
                    // Scrollable sideways, so reachable by keyboard: a long line is read by scrolling it

                    // (WCAG 2.1.1; axe scrollable-region-focusable), which the lint rule does not know.

                    // oxlint-disable-next-line jsx-a11y/no-noninteractive-tabindex
                    tabIndex={0}
                    aria-label={snippet.where}
                    className="mono text-xs rounded-lg px-3.5 py-3 overflow-x-auto"
                    style={{ background: 'var(--surface-2)', whiteSpace: 'pre' }}
                >
                    <pre className="m-0">
                        <code>{snippet.code}</code>
                    </pre>
                </section>
                <div className="mt-2">
                    <CopyButton
                        value={snippet.code}
                        size="sm"
                        label={tc('console.copy_e21f935f')}
                    />
                </div>
            </div>
        </div>
    );
}

/**
 * Signing in with your own account instead of pasting a key. Offered as a command only
 * when the server says the MCP resource accepts it; until then it is named, not offered.
 */
function SignInOption({ available, mcpUrl }: { available: boolean; mcpUrl: string }) {
    const { t: tc } = useTranslator();

    if (available) {
        return (
            <div>
                <p className="text-sm font-medium">
                    {tc('console.or_sign_in_with_an_account_of_this_environme_5aaaebd6')}
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.for_one_of_this_environment_s_own_people_an__1c327ded')}
                </p>
                <div className="mt-2">
                    <Snippet snippet={claudeCodeOAuth(mcpUrl)} />
                </div>
            </div>
        );
    }

    return (
        <div
            className="flex items-start gap-3 rounded-lg px-3.5 py-3"
            style={{ background: 'var(--surface-2)' }}
        >
            <Icon name="user" className="w-4 h-4 mt-0.5 shrink-0" />
            <div>
                <p className="text-sm font-medium flex items-center gap-2">
                    {tc('console.sign_in_with_your_account_15369047')}
                    <Badge>{tc('console.coming_soon_4f7d6401')}</Badge>
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.the_agent_will_act_as_you_with_your_access_a_dd9fa746')}
                </p>
            </div>
        </div>
    );
}

/**
 * The other way in: the platform root's `/mcp`, where the workspace's own people sign in.
 * One connection reaches the workspace and every environment of it the person administers,
 * so it is offered beside this environment's own server rather than instead of it.
 *
 * Signing the agent in at the root with the person's account is the first way offered: the
 * client needs nothing but the URL. The cbox CLI and a workspace key are the alternatives.
 */
function WorkspaceConnection({ workspace }: { workspace: WorkspaceProps }) {
    const { t: tc } = useTranslator();

    return (
        <Panel title={tc('console.one_connection_for_your_whole_workspace_7b9b1116')}>
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.connect_once_at_the_platform_root_instead_of_289076ee')}
                <span className="mono">{tc('console.environment_ba528516')}</span>{' '}
                {tc('console.argument_and_the_rest_api_a_d919155f')}
                <span className="mono">{tc('console.cbox_environment_31e7430f')}</span>
                {tc('console.header_naming_where_to_act_it_can_do_what_yo_a0fc3c07')}
            </p>
            <div className="mt-4 space-y-5">
                {workspace.oauth ? (
                    <div>
                        <p className="text-sm font-medium">
                            {tc('console.add_it_to_claude_code_and_sign_in_fb82849c')}
                        </p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.claude_code_opens_your_browser_to_sign_in_he_a86b6b5e')}
                        </p>
                        <div className="mt-2">
                            <Snippet snippet={claudeCodeOAuth(workspace.mcpUrl)} />
                        </div>
                    </div>
                ) : (
                    <div
                        className="flex items-start gap-3 rounded-lg px-3.5 py-3"
                        style={{ background: 'var(--surface-2)' }}
                    >
                        <Icon name="user" className="w-4 h-4 mt-0.5 shrink-0" />
                        <div>
                            <p className="text-sm font-medium">
                                {tc(
                                    'console.agents_signing_in_at_the_root_are_turned_off_080e7850',
                                )}
                            </p>
                            <p
                                className="text-xs mt-0.5"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {tc(
                                    'console.this_deployment_does_not_let_an_mcp_client_s_b8375ebb',
                                )}
                            </p>
                        </div>
                    </div>
                )}
                <div>
                    <p className="text-sm font-medium">
                        {workspace.oauth
                            ? tc('console.or_sign_the_cbox_cli_in_as_yourself_29d51963')
                            : tc('console.sign_the_cbox_cli_in_as_yourself_722a3ec5')}
                    </p>
                    <div className="mt-2">
                        <Snippet snippet={cboxLogin(workspace.issuer)} />
                    </div>
                </div>
                <div>
                    <p className="text-sm font-medium">
                        {tc('console.or_give_an_agent_a_workspace_key_03647658')}
                    </p>
                    <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.a_workspace_key_reaches_the_workspace_itself_3df144e4')}{' '}
                        {workspace.workspaceKeysHref === null ? (
                            tc('console.keys_workspace_keys_6b727150')
                        ) : (
                            <a href={workspace.workspaceKeysHref}>
                                {tc('console.keys_workspace_keys_6b727150')}
                            </a>
                        )}{' '}
                        {tc('console.at_the_platform_root_3928bb9f')}
                    </p>
                    <div className="mt-2">
                        <Snippet snippet={claudeCodeWorkspace(workspace.mcpUrl)} />
                    </div>
                </div>
            </div>
            <dl className="mt-5 grid gap-3 text-sm">
                <Endpoint
                    label={tc('console.mcp_server_platform_root_925c6860')}
                    value={workspace.mcpUrl}
                />
                <Endpoint
                    label={tc('console.rest_api_platform_root_061582e3')}
                    value={workspace.restBaseUrl}
                />
            </dl>
        </Panel>
    );
}

function Endpoint({
    label,
    value,
    external = false,
}: {
    label: string;
    value: string;
    external?: boolean;
}) {
    const { t: tc } = useTranslator();

    return (
        <div className="grid gap-1 sm:grid-cols-[14rem_1fr] sm:items-center">
            <dt style={{ color: 'var(--muted-foreground)' }}>{label}</dt>
            <dd className="flex items-center gap-2 min-w-0">
                {external ? (
                    <a
                        href={value}
                        target="_blank"
                        rel="noreferrer"
                        className="mono text-xs break-all"
                        style={{ color: 'var(--primary)' }}
                    >
                        {value} ↗
                    </a>
                ) : (
                    <span className="mono text-xs break-all">{value}</span>
                )}
                <CopyButton
                    value={value}
                    size="sm"
                    variant="ghost"
                    label={tc('console.copy_e21f935f')}
                />
            </dd>
        </div>
    );
}

ConnectAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
