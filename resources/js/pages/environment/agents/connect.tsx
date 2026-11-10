import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import {
    cboxLogin,
    claudeCode,
    claudeCodeOAuth,
    claudeCodeOrganization,
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
    organization: OrgConnectionProps | null;
    urls: { createAgent: string; agents: string };
    help: HelpContent;
}>;

interface OrgConnectionProps {
    mcpUrl: string;
    issuer: string;
    restBaseUrl: string;
    /** True while an MCP client may sign a person in at the root itself (`api.mcp.root_oauth`). */
    oauth: boolean;
    /** Keys › Workspace keys on the workspace's host; null on a single-host deployment. */
    organizationKeysHref: string | null;
}

const CLIENTS = (t: Translator['t']): { id: ClientId; label: string }[] => [
    { id: 'claude-code', label: 'Claude Code' },
    { id: 'claude-desktop', label: 'Claude Desktop' },
    { id: 'cursor', label: 'Cursor' },
    { id: 'vscode', label: 'VS Code' },
    { id: 'generic', label: t('console.generic_0228c6d4') },
];

export default function ConnectAgent({
    mcpUrl,
    metadataUrl,
    restBaseUrl,
    openApiUrl,
    oauthAvailable,
    organization: orgConnection,
    urls,
    help,
}: Props) {
    const { t: consoleOptionText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [client, setClient] = useState<ClientId>('claude-code');

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.this_environment_serves_an_mcp_server_next_to_its_rest_8094252a',
                )}
                actions={
                    <Button asChild size="sm" variant="primary" icon="plus">
                        <Link href={urls.createAgent}>
                            {consoleT('console.create_a_key_for_this_agent_f16f18d7')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-6" style={{ maxWidth: '52rem' }}>
                <Panel title={consoleT('console.mcp_server_d938c816')}>
                    <div className="flex items-center gap-2">
                        <code
                            className="mono text-sm break-all flex-1 rounded-lg px-3 py-2.5"
                            style={{ background: 'var(--surface-2)' }}
                        >
                            {mcpUrl}
                        </code>
                        <CopyButton value={mcpUrl} label={consoleT('console.copy_url_b26d1037')} />
                    </div>
                    <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.streamable_http_every_request_carries_part1_part2_a_man_6348600d',
                            {
                                part1: ' ',
                                part2: (
                                    <span className="mono">Authorization: Bearer cbid_env_…</span>
                                ),
                            },
                        )}
                    </p>
                </Panel>

                <Panel title={consoleT('console.set_up_your_client_facf6955')}>
                    <Tabs
                        value={client}
                        onValueChange={(next) => setClient(next as ClientId)}
                        label={consoleT('console.mcp_client_c6f98ebe')}
                        panels={
                            <div className="pt-4">
                                <TabPanel value="claude-code">
                                    <div className="space-y-5">
                                        <Snippet snippet={claudeCode(mcpUrl)} />
                                        <div>
                                            <p className="text-sm font-medium">
                                                {consoleT(
                                                    'console.share_it_with_your_team_474083cd',
                                                )}
                                            </p>
                                            <p
                                                className="text-xs mt-0.5"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {consoleT(
                                                    'console.commit_this_file_and_give_each_person_their_own_key_in_f23e11a2',
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
                                            {consoleT(
                                                'console.vs_code_asks_for_the_key_the_first_time_and_keeps_it_in_e11c90d5',
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
                        {CLIENTS(consoleOptionText).map((option) => (
                            <Tab key={option.id} value={option.id}>
                                {option.label}
                            </Tab>
                        ))}
                    </Tabs>
                </Panel>

                {orgConnection && <OrgConnection connection={orgConnection} />}

                <Panel title={consoleT('console.for_developers_6f79cf5c')}>
                    <dl className="grid gap-3 text-sm">
                        <Endpoint
                            label={consoleT('console.resource_metadata_rfc_9728_54cff8ab')}
                            value={metadataUrl}
                        />
                        <Endpoint
                            label={consoleT('console.rest_api_41a52587')}
                            value={restBaseUrl}
                        />
                        <Endpoint
                            label={consoleT('console.openapi_cf2c9e21')}
                            value={openApiUrl}
                            external
                        />
                    </dl>
                </Panel>

                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console.already_have_a_key_part1_part2_190f7234', {
                        part1: ' ',
                        part2: (
                            <Link href={urls.agents} style={{ color: 'var(--primary)' }}>
                                {consoleT('console.see_every_agent_and_what_it_can_do_d10856a4')}
                            </Link>
                        ),
                    })}
                </p>
            </div>
        </>
    );
}

function Snippet({ snippet }: { snippet: ClientSnippet }) {
    const { t: consoleT } = useConsoleTranslator();
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
                        label={consoleT('console.copy_e21f935f')}
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
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    if (available) {
        return (
            <div>
                <p className="text-sm font-medium">
                    {consoleT('console.or_sign_in_with_an_account_of_this_environment_5aaaebd6')}
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT(
                        'console.for_one_of_this_environment_s_own_people_an_organizatio_1c327ded',
                    )}
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
                    {consoleRich('console.sign_in_with_your_account_part1_5fae887f', {
                        part1: <Badge>{consoleT('console.coming_soon_4f7d6401')}</Badge>,
                    })}
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT(
                        'console.the_agent_will_act_as_you_with_your_access_and_no_key_t_dd9fa746',
                    )}
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
function OrgConnection({ connection }: { connection: OrgConnectionProps }) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Panel title={consoleT('console.one_connection_for_your_whole_organization_215c630d')}>
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {consoleRich(
                    'console.connect_once_at_the_platform_root_instead_of_once_per_e_df000287',
                    {
                        part1: <span className="mono">environment</span>,
                        part2: ' ',
                        part3: <span className="mono">Cbox-Environment</span>,
                    },
                )}
            </p>
            <div className="mt-4 space-y-5">
                {connection.oauth ? (
                    <div>
                        <p className="text-sm font-medium">
                            {consoleT('console.add_it_to_claude_code_and_sign_in_fb82849c')}
                        </p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT(
                                'console.claude_code_opens_your_browser_to_sign_in_here_and_asks_a86b6b5e',
                            )}
                        </p>
                        <div className="mt-2">
                            <Snippet snippet={claudeCodeOAuth(connection.mcpUrl)} />
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
                                {consoleT(
                                    'console.agents_signing_in_at_the_root_are_turned_off_080e7850',
                                )}
                            </p>
                            <p
                                className="text-xs mt-0.5"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {consoleT(
                                    'console.this_deployment_does_not_let_an_mcp_client_sign_you_in__2bbe3144',
                                )}
                            </p>
                        </div>
                    </div>
                )}
                <div>
                    <p className="text-sm font-medium">
                        {connection.oauth
                            ? consoleText('console.or_sign_the_cbox_cli_in_as_yourself_29d51963')
                            : consoleText('console.sign_the_cbox_cli_in_as_yourself_722a3ec5')}
                    </p>
                    <div className="mt-2">
                        <Snippet snippet={cboxLogin(connection.issuer)} />
                    </div>
                </div>
                <div>
                    <p className="text-sm font-medium">
                        {consoleT('console.or_give_an_agent_an_organization_key_2a85f987')}
                    </p>
                    <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.an_organization_key_reaches_the_organization_itself_pro_0257779c',
                            {
                                part1: ' ',
                                part2:
                                    connection.organizationKeysHref === null ? (
                                        consoleNestedText('console.keys_organization_keys_a97fe2c6')
                                    ) : (
                                        <a href={connection.organizationKeysHref}>
                                            {consoleT('console.keys_organization_keys_a97fe2c6')}
                                        </a>
                                    ),
                                part3: ' ',
                            },
                        )}
                    </p>
                    <div className="mt-2">
                        <Snippet snippet={claudeCodeOrganization(connection.mcpUrl)} />
                    </div>
                </div>
            </div>
            <dl className="mt-5 grid gap-3 text-sm">
                <Endpoint
                    label={consoleT('console.mcp_server_platform_root_925c6860')}
                    value={connection.mcpUrl}
                />
                <Endpoint
                    label={consoleT('console.rest_api_platform_root_061582e3')}
                    value={connection.restBaseUrl}
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
    const { t: consoleT } = useConsoleTranslator();
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
                    label={consoleT('console.copy_e21f935f')}
                />
            </dd>
        </div>
    );
}

ConnectAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
