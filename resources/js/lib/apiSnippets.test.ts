import { type ApiAction, argumentsFor, cli, curl, lines, mcp, sdk, urlFor } from './apiSnippets';

/** `apps.create` as the server describes it. */
function createApp(overrides: Partial<ApiAction> = {}): ApiAction {
    return {
        name: 'apps.create',
        summary: 'Register an app.',
        method: 'POST',
        path: '/apps',
        url: 'https://acme.cboxid.com/api/v1/apps',
        scope: 'apps:write',
        danger: 'critical',
        plane: 'environment',
        tool: 'apps_create',
        cli: 'cbox id apps:create',
        cliShipped: false,
        sdk: 'env.apps.create',
        sdkPreview: false,
        redact: ['client_secret'],
        fields: [
            {
                name: 'name',
                type: 'string',
                required: false,
                in: 'body',
                secret: false,
                description: null,
                enum: null,
                value: null,
            },
            {
                name: 'type',
                type: 'string',
                required: false,
                in: 'body',
                secret: false,
                description: null,
                enum: ['web', 'spa'],
                value: null,
            },
            {
                name: 'redirect_uris',
                type: 'array',
                required: false,
                in: 'body',
                secret: false,
                description: null,
                enum: null,
                value: null,
            },
            {
                name: 'first_party',
                type: 'boolean',
                required: false,
                in: 'body',
                secret: false,
                description: null,
                enum: null,
                value: null,
            },
        ],
        ...overrides,
    };
}

/** `apps.secrets.rotate`: a path id, and a secret it must never echo. */
function rotate(value: string | null = 'app_01'): ApiAction {
    return createApp({
        name: 'apps.secrets.rotate',
        path: value === null ? '/apps/{id}/secrets' : `/apps/${value}/secrets`,
        url: 'https://acme.cboxid.com/api/v1/apps/{id}/secrets',
        tool: 'apps_secrets_rotate',
        sdk: 'env.apps.secrets.rotate',
        fields: [
            {
                name: 'id',
                type: 'string',
                required: true,
                in: 'path',
                secret: false,
                description: null,
                enum: null,
                value,
            },
            {
                name: 'grace_seconds',
                type: 'integer',
                required: false,
                in: 'body',
                secret: false,
                description: null,
                enum: null,
                value: null,
            },
            {
                name: 'client_secret',
                type: 'string',
                required: false,
                in: 'body',
                secret: true,
                description: null,
                enum: null,
                value: null,
            },
        ],
    });
}

describe('API equivalents', () => {
    it('writes curl with the form values, the key from the environment and an idempotency key', () => {
        const code = curl(createApp(), {
            name: 'Shop',
            type: 'web',
            redirect_uris: ['https://shop.example/cb'],
            someFormBookkeeping: 'x',
        });

        expect(code).toContain("curl -X POST 'https://acme.cboxid.com/api/v1/apps'");
        expect(code).toContain('Authorization: Bearer $CBOX_ID_MANAGEMENT_KEY');
        expect(code).toContain('Idempotency-Key');
        expect(code).toContain('"name": "Shop"');
        expect(code).toContain('"https://shop.example/cb"');
        // Only what the action declares — a form's own fields never reach the API.
        expect(code).not.toContain('someFormBookkeeping');
    });

    it('never writes a secret the form holds — it reads it from the environment', () => {
        const values = { id: 'app_01', client_secret: 'csec_live_value' };

        for (const write of [curl, mcp, cli, sdk]) {
            expect(write(rotate(), values)).not.toContain('csec_live_value');
        }

        expect(curl(rotate(), values)).toContain(`"'"$CLIENT_SECRET"'"`);
        expect(sdk(rotate(), values)).toContain('process.env.CLIENT_SECRET!');
    });

    it('fills the page id into the path, and a placeholder where nobody knows it', () => {
        expect(urlFor(rotate(), argumentsFor(rotate()))).toBe(
            'https://acme.cboxid.com/api/v1/apps/app_01/secrets',
        );
        expect(urlFor(rotate(null), argumentsFor(rotate(null)))).toBe(
            'https://acme.cboxid.com/api/v1/apps/<id>/secrets',
        );
        // The form can name a different record than the page's own.
        expect(urlFor(rotate(), argumentsFor(rotate(), { id: 'app_02' }))).toContain(
            '/apps/app_02/',
        );
    });

    it('calls the MCP tool with the arguments, path ids included', () => {
        const call = JSON.parse(mcp(rotate(), { grace_seconds: 3600 }));

        expect(call).toEqual({
            name: 'apps_secrets_rotate',
            arguments: { id: 'app_01', grace_seconds: 3600 },
        });
    });

    it('writes the CLI with the id as an argument and the rest as options', () => {
        expect(cli(rotate(), { grace_seconds: 3600 })).toBe(
            'cbox id apps:create app_01 --grace-seconds=3600',
        );
        expect(
            cli(createApp(), { name: 'My shop', redirect_uris: ['a', 'b'], first_party: true }),
        ).toBe(
            "cbox id apps:create --name='My shop' --redirect-uris=a --redirect-uris=b --first-party",
        );
    });

    it('writes the id-js management call: path ids first, then the body', () => {
        const code = sdk(rotate(), { grace_seconds: 3600 });

        expect(code).toContain("import { EnvironmentClient } from '@cboxdk/id-js/management'");
        expect(code).toContain("baseUrl: 'https://acme.cboxid.com'");
        expect(code).toContain('apiKey: process.env.CBOX_ID_MANAGEMENT_KEY!');
        expect(code).toContain(
            'await env.apps.secrets.rotate("app_01", {\n  "grace_seconds": 3600\n})',
        );
    });

    it("uses the plane's own client and credential", () => {
        const workspace = sdk(
            createApp({ plane: 'workspace', sdk: 'workspace.projects.create' }),
            {},
        );

        expect(workspace).toContain('new WorkspaceClient(');
        expect(workspace).toContain('process.env.CBOX_ID_WORKSPACE_KEY');
        expect(sdk(createApp({ plane: 'account', sdk: 'account.profile.update' }))).toContain(
            'accessToken:',
        );
    });

    it('fills a required field nobody has filled with something that reads as a blank', () => {
        const action = createApp({
            fields: [
                {
                    name: 'email',
                    type: 'string',
                    required: true,
                    in: 'body',
                    secret: false,
                    description: null,
                    enum: null,
                    value: null,
                },
            ],
        });

        expect(argumentsFor(action)).toEqual({ email: '<email>' });
    });

    it('splits a textarea into the list an action takes', () => {
        expect(lines(' https://a.example/cb \n\n https://b.example/cb')).toEqual([
            'https://a.example/cb',
            'https://b.example/cb',
        ]);
    });

    it("puts a read's arguments in the query string", () => {
        const list = createApp({
            method: 'GET',
            url: 'https://acme.cboxid.com/api/v1/apps',
            danger: 'read',
        });

        expect(curl(list, { name: 'Shop' })).toContain('/api/v1/apps?name=Shop');
        expect(curl(list, { name: 'Shop' })).not.toContain('-d ');
    });
});
