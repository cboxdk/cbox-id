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
    workspace: { mcpUrl: string; issuer: string; restBaseUrl: string } | null;
    urls: { createAgent: string; agents: string };
    help: HelpContent;
}>;

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
    const [client, setClient] = useState<ClientId>('claude-code');

    return (
        <>
            <PageHeader
                help={help}
                description="This environment serves an MCP server next to its REST API. Point an agent at it with a management key, and it can do exactly what the key's scopes allow — with the same checks, approvals and activity log as the console."
                actions={
                    <Button asChild size="sm" variant="primary" icon="plus">
                        <Link href={urls.createAgent}>Create a key for this agent</Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-6" style={{ maxWidth: '52rem' }}>
                <Panel title="MCP server">
                    <div className="flex items-center gap-2">
                        <code
                            className="mono text-sm break-all flex-1 rounded-lg px-3 py-2.5"
                            style={{ background: 'var(--surface-2)' }}
                        >
                            {mcpUrl}
                        </code>
                        <CopyButton value={mcpUrl} label="Copy URL" />
                    </div>
                    <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Streamable HTTP. Every request carries{' '}
                        <span className="mono">Authorization: Bearer cbid_env_…</span> — a
                        management key of this environment.
                    </p>
                </Panel>

                <Panel title="Set up your client">
                    <Tabs
                        value={client}
                        onValueChange={(next) => setClient(next as ClientId)}
                        label="MCP client"
                        panels={
                            <div className="pt-4">
                                <TabPanel value="claude-code">
                                    <div className="space-y-5">
                                        <Snippet snippet={claudeCode(mcpUrl)} />
                                        <div>
                                            <p className="text-sm font-medium">
                                                Share it with your team
                                            </p>
                                            <p
                                                className="text-xs mt-0.5"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                Commit this file and give each person their own key
                                                in the environment variable — the file itself holds
                                                no secret.
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
                                            VS Code asks for the key the first time and keeps it in
                                            its own secret storage.
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

                <Panel title="For developers">
                    <dl className="grid gap-3 text-sm">
                        <Endpoint label="Resource metadata (RFC 9728)" value={metadataUrl} />
                        <Endpoint label="REST API" value={restBaseUrl} />
                        <Endpoint label="OpenAPI" value={openApiUrl} external />
                    </dl>
                </Panel>

                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Already have a key?{' '}
                    <Link href={urls.agents} style={{ color: 'var(--primary)' }}>
                        See every agent and what it can do
                    </Link>
                    .
                </p>
            </div>
        </>
    );
}

function Snippet({ snippet }: { snippet: ClientSnippet }) {
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
                    <pre className="m-0"><code>{snippet.code}</code></pre>
                </section>
                <div className="mt-2">
                    <CopyButton value={snippet.code} size="sm" label="Copy" />
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
    if (available) {
        return (
            <div>
                <p className="text-sm font-medium">Or sign in with an account of this environment</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    For one of this environment's own people — an organization's
                    administrator. The agent acts as them, in their organization.
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
                    Sign in with your account <Badge>Coming soon</Badge>
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                    The agent will act as you, with your access, and no key to keep safe. Until
                    then, a management key is the way in.
                </p>
            </div>
        </div>
    );
}

/**
 * The other way in: the platform root's `/mcp`, where the workspace's own people sign in.
 * One connection reaches the workspace and every environment of it the person administers,
 * so it is offered beside this environment's own server rather than instead of it.
 */
function WorkspaceConnection({
    workspace,
}: {
    workspace: { mcpUrl: string; issuer: string; restBaseUrl: string };
}) {
    return (
        <Panel title="One connection for your whole workspace">
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Connect once at the platform root instead of once per environment. Signed in
                as you, it reaches your workspace, every environment of it you administer and
                your own account — an environment tool takes an <span className="mono">environment</span>{' '}
                argument, and the REST API a <span className="mono">Cbox-Environment</span>{' '}
                header, naming where to act. It can do what you can, within what you allow,
                and every critical action waits for your approval on your device.
            </p>
            <div className="mt-4 space-y-5">
                <div>
                    <p className="text-sm font-medium">Sign the cbox CLI in as yourself</p>
                    <div className="mt-2">
                        <Snippet snippet={cboxLogin(workspace.issuer)} />
                    </div>
                </div>
                <div>
                    <p className="text-sm font-medium">Or give an agent a workspace key</p>
                    <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        A workspace key reaches the workspace itself — projects, environments,
                        the team and keys — not inside an environment. Mint one under Keys ›
                        Workspace keys at the platform root.
                    </p>
                    <div className="mt-2">
                        <Snippet snippet={claudeCodeWorkspace(workspace.mcpUrl)} />
                    </div>
                </div>
                <div
                    className="flex items-start gap-3 rounded-lg px-3.5 py-3"
                    style={{ background: 'var(--surface-2)' }}
                >
                    <Icon name="user" className="w-4 h-4 mt-0.5 shrink-0" />
                    <div>
                        <p className="text-sm font-medium flex items-center gap-2">
                            Agents signing in as you at the root <Badge>Coming soon</Badge>
                        </p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                            Until then, an agent acts as you across the workspace through the
                            token the cbox CLI holds, or here per environment with a key.
                        </p>
                    </div>
                </div>
            </div>
            <dl className="mt-5 grid gap-3 text-sm">
                <Endpoint label="MCP server (platform root)" value={workspace.mcpUrl} />
                <Endpoint label="REST API (platform root)" value={workspace.restBaseUrl} />
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
                <CopyButton value={value} size="sm" variant="ghost" label="Copy" />
            </dd>
        </div>
    );
}

ConnectAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
