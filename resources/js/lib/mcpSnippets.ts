/**
 * How each MCP client is pointed at an environment's `/mcp` server — one function per
 * client, returning exactly what a person pastes.
 *
 * The key is a parameter rather than baked in: the Connect page shows a placeholder, and the
 * page that has just minted a key shows the real one, once. Where a client reads a config
 * FILE that tends to be committed, the snippet reads the key from an environment variable
 * instead, so the copy that lands in a repository holds no secret.
 */

export const SERVER_NAME = 'cbox-id';

/** What the snippets show where a key goes, until one has been minted. */
export const KEY_PLACEHOLDER = 'cbid_env_…';

/** The environment variable the file-based snippets read the key from. */
export const KEY_ENV = 'CBOX_ID_MANAGEMENT_KEY';

export type ClientId = 'claude-code' | 'claude-desktop' | 'cursor' | 'vscode' | 'generic';

export interface ClientSnippet {
    /** Where it goes, in words: a terminal, a file path. */
    where: string;
    language: 'bash' | 'json' | 'text';
    code: string;
}

function json(value: unknown): string {
    return JSON.stringify(value, null, 2);
}

/** `claude mcp add` with the key as a header — Claude Code's own command. */
export function claudeCode(url: string, key: string = KEY_PLACEHOLDER): ClientSnippet {
    return {
        where: 'In a terminal',
        language: 'bash',
        code: `claude mcp add --transport http ${SERVER_NAME} ${url} --header "Authorization: Bearer ${key}"`,
    };
}

/**
 * The same, signing in with the person's account instead of a key: Claude Code discovers
 * the authorization server from the resource's metadata and runs the browser flow itself.
 */
export function claudeCodeOAuth(url: string): ClientSnippet {
    return {
        where: 'In a terminal',
        language: 'bash',
        code: `claude mcp add --transport http ${SERVER_NAME} ${url}`,
    };
}

/** The name the workspace-wide server is added under, beside an environment's. */
export const WORKSPACE_SERVER_NAME = 'cbox-workspace';

/** What the workspace snippet shows where a workspace key goes. */
export const WORKSPACE_KEY_PLACEHOLDER = 'cbid_ws_…';

/**
 * The platform root's `/mcp`, with a WORKSPACE key: one server for the whole workspace —
 * its projects, environments, team and keys — rather than one per environment.
 */
export function claudeCodeWorkspace(url: string, key: string = WORKSPACE_KEY_PLACEHOLDER): ClientSnippet {
    return {
        where: 'In a terminal',
        language: 'bash',
        code: `claude mcp add --transport http ${WORKSPACE_SERVER_NAME} ${url} --header "Authorization: Bearer ${key}"`,
    };
}

/**
 * Signing the `cbox` CLI in as yourself at the platform root — one sign-in for the
 * workspace, every environment of it you administer, your account and, for an operator,
 * the deployment.
 */
export function cboxLogin(issuer: string): ClientSnippet {
    return {
        where: 'In a terminal',
        language: 'bash',
        code: `cbox login --issuer ${issuer}`,
    };
}

/**
 * Claude Desktop speaks to remote servers through the `mcp-remote` bridge. The header
 * goes through an environment variable because the bridge splits arguments on spaces.
 */
export function claudeDesktop(url: string, key: string = KEY_PLACEHOLDER): ClientSnippet {
    return {
        where: 'claude_desktop_config.json (Settings › Developer › Edit config)',
        language: 'json',
        code: json({
            mcpServers: {
                [SERVER_NAME]: {
                    command: 'npx',
                    args: ['-y', 'mcp-remote', url, '--header', 'Authorization:${AUTH_HEADER}'],
                    env: { AUTH_HEADER: `Bearer ${key}` },
                },
            },
        }),
    };
}

export function cursor(url: string, key: string = KEY_PLACEHOLDER): ClientSnippet {
    return {
        where: '.cursor/mcp.json',
        language: 'json',
        code: json({
            mcpServers: {
                [SERVER_NAME]: {
                    url,
                    headers: { Authorization: `Bearer ${key}` },
                },
            },
        }),
    };
}

/**
 * VS Code's own `servers` shape, with the key as a prompted input: VS Code asks for it once
 * and keeps it in its secret storage, so the workspace file holds no secret at all.
 */
export function vscode(url: string): ClientSnippet {
    return {
        where: '.vscode/mcp.json',
        language: 'json',
        code: json({
            inputs: [
                {
                    type: 'promptString',
                    id: 'cbox-id-key',
                    description: 'Cbox ID management key',
                    password: true,
                },
            ],
            servers: {
                [SERVER_NAME]: {
                    type: 'http',
                    url,
                    headers: { Authorization: 'Bearer ${input:cbox-id-key}' },
                },
            },
        }),
    };
}

/** A project-shared `.mcp.json` for Claude Code, reading the key from the environment. */
export function sharedProjectConfig(url: string): ClientSnippet {
    return {
        where: '.mcp.json (safe to commit)',
        language: 'json',
        code: json({
            mcpServers: {
                [SERVER_NAME]: {
                    type: 'http',
                    url,
                    headers: { Authorization: `Bearer \${${KEY_ENV}}` },
                },
            },
        }),
    };
}

export interface GenericEndpoints {
    mcpUrl: string;
    metadataUrl: string;
    restBaseUrl: string;
    openApiUrl: string;
}

/** For any other client: the four addresses and the header, as plain lines. */
export function generic(endpoints: GenericEndpoints, key: string = KEY_PLACEHOLDER): ClientSnippet {
    return {
        where: 'Any MCP client (Streamable HTTP)',
        language: 'text',
        code: [
            `MCP server:          ${endpoints.mcpUrl}`,
            `Header:              Authorization: Bearer ${key}`,
            `Resource metadata:   ${endpoints.metadataUrl}`,
            `REST API:            ${endpoints.restBaseUrl}`,
            `OpenAPI:             ${endpoints.openApiUrl}`,
        ].join('\n'),
    };
}
