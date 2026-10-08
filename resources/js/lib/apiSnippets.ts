/**
 * THE API TWIN OF A CONSOLE ACTION, written four ways: curl, an MCP tool call, the `cbox`
 * CLI and `@cboxdk/id-js`.
 *
 * Every console form runs an action, and every action is also a REST endpoint, an MCP tool
 * and a CLI command. The server says which actions a page hosts and the facts about each
 * ({@link ApiAction}, from `App\Http\Props\Console\ApiEquivalentProps`); the snippets are
 * written here, in the browser, because "Copy as curl" fills in what the form holds NOW.
 *
 * NEVER A SECRET. A field the server marks secret — by its name, or because the action
 * redacts it — is written as an environment variable (`$CLIENT_SECRET`), whatever the form
 * holds. A snippet is something people paste into tickets and shell history.
 */

export type ApiDanger = 'read' | 'write' | 'destructive' | 'critical';

export type ApiPlane = 'environment' | 'workspace' | 'platform' | 'account';

export interface ApiField {
    name: string;
    type: string;
    required: boolean;
    in: 'path' | 'query' | 'body';
    secret: boolean;
    description: string | null;
    enum: (string | number | boolean)[] | null;
    /** The page's own id, already filled in for a path field. */
    value: string | null;
}

/** `App\Http\Props\Console\ApiEquivalentProps` */
export interface ApiAction {
    name: string;
    summary: string;
    method: string;
    /** Below `/api/v1`, with the page's ids filled in where it knew them. */
    path: string;
    url: string;
    scope: string;
    danger: ApiDanger;
    plane: ApiPlane;
    tool: string;
    cli: string;
    /** Whether the CLI has this command today; otherwise it is the name it will have. */
    cliShipped: boolean;
    /** The `@cboxdk/id-js/management` method: `env.apps.secrets.rotate`. */
    sdk: string;
    /** Whether the published SDK lacks this call, so the snippet is what it will look like. */
    sdkPreview: boolean;
    fields: ApiField[];
    redact: string[];
}

export type SnippetValues = Record<string, unknown>;

/** Where each plane's credential is read from, in every snippet. */
export function tokenEnv(plane: ApiPlane): string {
    switch (plane) {
        case 'environment':
            return 'CBOX_ID_MANAGEMENT_KEY';
        case 'workspace':
            return 'CBOX_ID_WORKSPACE_KEY';
        default:
            return 'CBOX_ID_ACCESS_TOKEN';
    }
}

/** A textarea's lines as the list an action takes: trimmed, blanks dropped. */
export function lines(text: string): string[] {
    return text
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

function envName(field: string): string {
    return field.replace(/[^a-z0-9]+/gi, '_').toUpperCase();
}

function isEmpty(value: unknown): boolean {
    return (
        value === undefined ||
        value === null ||
        value === '' ||
        (Array.isArray(value) && value.length === 0)
    );
}

/** What a required field nobody has filled in yet is written as. */
function placeholder(field: ApiField): unknown {
    if (field.enum !== null && field.enum.length > 0) {
        return field.enum[0];
    }

    switch (field.type) {
        case 'boolean':
            return false;
        case 'integer':
            return 0;
        case 'array':
            return [`<${field.name}>`];
        case 'object':
            return {};
        default:
            return `<${field.name}>`;
    }
}

/**
 * The arguments the action is called with: the form's values where it has them, a
 * placeholder for a required field it has not, and an environment variable for a secret.
 * Only fields the action declares — a form's own bookkeeping never leaks into a snippet.
 */
export function argumentsFor(
    action: ApiAction,
    values: SnippetValues = {},
): Record<string, unknown> {
    const out: Record<string, unknown> = {};

    for (const field of action.fields) {
        const given = values[field.name] ?? (field.in === 'path' ? field.value : undefined);

        if (field.secret) {
            if (!isEmpty(given) || field.required) {
                out[field.name] = `$${envName(field.name)}`;
            }

            continue;
        }

        if (!isEmpty(given)) {
            out[field.name] = given;
        } else if (field.required) {
            out[field.name] = placeholder(field);
        }
    }

    // Nothing to send for a write that takes something — the page's own disclosure, an empty
    // list — reads as a mistake. A `name` is what nearly every create needs; show its slot.
    const writes = !['GET', 'DELETE'].includes(action.method);
    const sendsNothing = Object.keys(out).every((name) =>
        action.fields.some((field) => field.name === name && field.in === 'path'),
    );
    const named = action.fields.find((field) => field.name === 'name' && field.in === 'body');

    if (writes && sendsNothing && named !== undefined) {
        out.name = '<name>';
    }

    return out;
}

function splitArguments(action: ApiAction, args: Record<string, unknown>) {
    const path: Record<string, unknown> = {};
    const rest: Record<string, unknown> = {};

    for (const field of action.fields) {
        if (!(field.name in args)) {
            continue;
        }

        if (field.in === 'path') {
            path[field.name] = args[field.name];
        } else {
            rest[field.name] = args[field.name];
        }
    }

    return { path, rest };
}

/** The URL with every path field in it — a value where there is one, `<name>` where not. */
export function urlFor(action: ApiAction, args: Record<string, unknown>): string {
    return action.url.replace(/\{([^}]+)\}|%7B([^%]+)%7D/g, (_match, a?: string, b?: string) => {
        const name = (a ?? b) as string;
        const value = args[name];

        if (isEmpty(value)) {
            return `<${name}>`;
        }

        const text = String(value);

        // A placeholder stays readable: it is what the reader replaces, not a value.
        return /^<[^>]+>$/.test(text) ? text : encodeURIComponent(text);
    });
}

/** A value inside single quotes in a POSIX shell. */
function shellQuote(value: string): string {
    return `'${value.replace(/'/g, `'\\''`)}'`;
}

/**
 * A JSON body for the shell. A secret stays an unexpanded `$VAR` inside single quotes, so
 * it is spliced in as a double-quoted shell expansion instead.
 */
function shellJson(body: Record<string, unknown>): string {
    const json = JSON.stringify(body, null, 2);
    const quoted = shellQuote(json);

    return quoted.replace(/"\$([A-Z0-9_]+)"/g, `"'"$$$1"'"`);
}

function queryString(rest: Record<string, unknown>): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(rest)) {
        if (Array.isArray(value)) {
            for (const item of value) {
                params.append(`${key}[]`, String(item));
            }
        } else if (typeof value === 'object' && value !== null) {
            params.append(key, JSON.stringify(value));
        } else {
            params.append(key, String(value));
        }
    }

    const query = params.toString();

    return query === '' ? '' : `?${query}`;
}

export function curl(action: ApiAction, values: SnippetValues = {}): string {
    const args = argumentsFor(action, values);
    const { rest } = splitArguments(action, args);
    const hasBody = !['GET', 'DELETE'].includes(action.method);
    const url = urlFor(action, args) + (hasBody ? '' : queryString(rest));
    const parts = [
        `curl -X ${action.method} ${shellQuote(url)}`,
        `  -H "Authorization: Bearer $${tokenEnv(action.plane)}"`,
    ];

    if (hasBody) {
        parts.push(`  -H 'Content-Type: application/json'`);

        if (action.danger !== 'read') {
            // Safe to retry: the same key replays the first answer instead of running twice.
            parts.push(`  -H "Idempotency-Key: $(uuidgen)"`);
        }

        parts.push(`  -d ${shellJson(rest)}`);
    }

    return parts.join(' \\\n');
}

/** The MCP `tools/call` an agent makes: the tool's name and its arguments, path ids included. */
export function mcp(action: ApiAction, values: SnippetValues = {}): string {
    return JSON.stringify({ name: action.tool, arguments: argumentsFor(action, values) }, null, 2);
}

function kebab(name: string): string {
    return name.replace(/_/g, '-');
}

function cliValue(value: unknown): string {
    const text = typeof value === 'object' ? JSON.stringify(value) : String(value);

    // A secret stays an expansion; anything with a space or a quote is quoted.
    if (/^\$[A-Z0-9_]+$/.test(text)) {
        return `"${text}"`;
    }

    return /^[\w@%+=:,./-]+$/.test(text) ? text : shellQuote(text);
}

/** `cbox id <area>:<verb>`, the path ids as arguments and every other field an option. */
export function cli(action: ApiAction, values: SnippetValues = {}): string {
    const args = argumentsFor(action, values);
    const { path, rest } = splitArguments(action, args);
    const parts = [action.cli];

    for (const value of Object.values(path)) {
        parts.push(isEmpty(value) ? '<id>' : cliValue(value));
    }

    for (const [key, value] of Object.entries(rest)) {
        if (Array.isArray(value)) {
            for (const item of value) {
                parts.push(`--${kebab(key)}=${cliValue(item)}`);
            }
        } else if (value === true) {
            parts.push(`--${kebab(key)}`);
        } else if (value !== false) {
            parts.push(`--${kebab(key)}=${cliValue(value)}`);
        }
    }

    return parts.join(' ');
}

const SDK_CLIENTS: Record<ApiPlane, { name: string; variable: string; credential: string }> = {
    environment: { name: 'EnvironmentClient', variable: 'env', credential: 'apiKey' },
    workspace: { name: 'WorkspaceClient', variable: 'workspace', credential: 'apiKey' },
    platform: { name: 'PlatformClient', variable: 'platform', credential: 'accessToken' },
    account: { name: 'AccountClient', variable: 'account', credential: 'accessToken' },
};

function origin(url: string): string {
    try {
        return new URL(url).origin;
    } catch {
        return url.replace(/\/api\/v1.*$/, '');
    }
}

/**
 * The `@cboxdk/id-js/management` call: the plane's client, then the method named after
 * the action — path ids first, in path order, then the body (or the query, for a read).
 */
export function sdk(action: ApiAction, values: SnippetValues = {}): string {
    const args = argumentsFor(action, values);
    const { path, rest } = splitArguments(action, args);
    const client = SDK_CLIENTS[action.plane];
    const params = Object.values(path).map((value) =>
        JSON.stringify(isEmpty(value) ? '<id>' : String(value)),
    );

    if (Object.keys(rest).length > 0) {
        params.push(JSON.stringify(rest, null, 2).replace(/"\$([A-Z0-9_]+)"/g, 'process.env.$1!'));
    }

    return [
        `import { ${client.name} } from '@cboxdk/id-js/management'`,
        '',
        `const ${client.variable} = new ${client.name}({`,
        `  baseUrl: '${origin(action.url)}',`,
        `  ${client.credential}: process.env.${tokenEnv(action.plane)}!,`,
        '})',
        '',
        `const { data } = await ${action.sdk}(${params.join(', ')})`,
    ].join('\n');
}
