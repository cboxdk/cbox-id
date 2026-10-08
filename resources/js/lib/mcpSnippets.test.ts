import {
    cboxLogin,
    claudeCode,
    claudeCodeOAuth,
    claudeCodeWorkspace,
    claudeDesktop,
    cursor,
    generic,
    KEY_ENV,
    KEY_PLACEHOLDER,
    sharedProjectConfig,
    vscode,
} from './mcpSnippets';

const URL = 'https://acme.cboxid.com/mcp';
const KEY = 'cbid_env_abc123';

describe('MCP client snippets', () => {
    it('adds the server to Claude Code with the key as a bearer header', () => {
        expect(claudeCode(URL, KEY).code).toBe(
            'claude mcp add --transport http cbox-id https://acme.cboxid.com/mcp --header "Authorization: Bearer cbid_env_abc123"',
        );
    });

    it('shows a placeholder until a key exists, and never an empty bearer', () => {
        expect(claudeCode(URL).code).toContain(`Bearer ${KEY_PLACEHOLDER}`);
        expect(cursor(URL).code).toContain(KEY_PLACEHOLDER);
    });

    it('drops the header when the person signs in instead', () => {
        expect(claudeCodeOAuth(URL).code).toBe(
            'claude mcp add --transport http cbox-id https://acme.cboxid.com/mcp',
        );
        expect(claudeCodeOAuth(URL).code).not.toContain('Authorization');
    });

    it('adds the workspace-wide server at the root under its own name, with a workspace key', () => {
        expect(claudeCodeWorkspace('https://cboxid.com/mcp').code).toBe(
            'claude mcp add --transport http cbox-workspace https://cboxid.com/mcp --header "Authorization: Bearer cbid_ws_…"',
        );
    });

    it('signs the cbox CLI in at the root', () => {
        expect(cboxLogin('https://cboxid.com').code).toBe('cbox login --issuer https://cboxid.com');
    });

    it('bridges Claude Desktop through mcp-remote, with the header in the environment', () => {
        const config = JSON.parse(claudeDesktop(URL, KEY).code);
        const server = config.mcpServers['cbox-id'];

        expect(server.command).toBe('npx');
        expect(server.args).toEqual([
            '-y',
            'mcp-remote',
            URL,
            '--header',
            'Authorization:${AUTH_HEADER}',
        ]);
        expect(server.env.AUTH_HEADER).toBe(`Bearer ${KEY}`);
    });

    it("writes Cursor's mcpServers shape", () => {
        const config = JSON.parse(cursor(URL, KEY).code);

        expect(config).toEqual({
            mcpServers: { 'cbox-id': { url: URL, headers: { Authorization: `Bearer ${KEY}` } } },
        });
    });

    it("keeps the key out of VS Code's workspace file by prompting for it", () => {
        const snippet = vscode(URL);
        const config = JSON.parse(snippet.code);

        expect(snippet.code).not.toContain('cbid_env_');
        expect(config.servers['cbox-id']).toEqual({
            type: 'http',
            url: URL,
            headers: { Authorization: 'Bearer ${input:cbox-id-key}' },
        });
        expect(config.inputs[0]).toMatchObject({ id: 'cbox-id-key', password: true });
    });

    it('reads the key from the environment in the file a team commits', () => {
        const config = JSON.parse(sharedProjectConfig(URL).code);

        expect(config.mcpServers['cbox-id'].headers.Authorization).toBe(`Bearer \${${KEY_ENV}}`);
    });

    it('lists every address a generic client needs', () => {
        const code = generic({
            mcpUrl: URL,
            metadataUrl: 'https://acme.cboxid.com/.well-known/oauth-protected-resource/mcp',
            restBaseUrl: 'https://acme.cboxid.com/api/v1',
            openApiUrl: 'https://acme.cboxid.com/api/v1/environment/openapi.yaml',
        }).code;

        expect(code).toContain(URL);
        expect(code).toContain('/.well-known/oauth-protected-resource/mcp');
        expect(code).toContain('/api/v1');
        expect(code).toContain('openapi.yaml');
        expect(code).toContain(`Authorization: Bearer ${KEY_PLACEHOLDER}`);
    });
});
