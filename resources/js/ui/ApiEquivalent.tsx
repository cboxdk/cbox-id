import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { usePage } from '@inertiajs/react';
import { useId, useMemo, useState } from 'react';
import {
    type ApiAction,
    cli,
    curl,
    mcp,
    type SnippetValues,
    sdk,
    tokenEnv,
} from '@/lib/apiSnippets';
import type { SharedProps } from '@/types';
import { Button } from './Button';
import { Dialog } from './Dialog';
import { Icon } from './Icon';
import { Pill } from './Pill';
import { Select } from './Select';
import { type CodeSnippet, SnippetTabs } from './SnippetTabs';

export interface ApiEquivalentProps {
    /** The action this form runs: `apps.create`. Nothing renders if the page does not host it. */
    action: string;
    /**
     * What the form holds now, in the ACTION's input names (`redirect_uris`, not
     * `redirectUris`) — so "copy as curl" is the request the form would send. Secrets are
     * never written out whatever is here.
     */
    values?: SnippetValues;
    /** Open on first render — for an empty state, where the code IS the next step. */
    defaultOpen?: boolean;
    /** The disclosure's label. */
    label?: string;
}

/** The actions the current page hosts, from the shared prop. */
export function usePageActions(): Record<string, ApiAction> {
    return usePage<SharedProps>().props.apiEquivalents ?? {};
}

/**
 * "</> API" — the same operation as this form, as curl, an MCP tool call, a CLI command and
 * an SDK call.
 *
 * A DISCLOSURE beside the submit rather than a page of its own: the moment somebody wants
 * the API twin is the moment they have just filled the form in, and the snippet is that
 * form's request. Closed by default so the form stays the form.
 */
export function ApiEquivalent({
    action,
    values,
    defaultOpen = false,
    label: suppliedLabel,
}: ApiEquivalentProps) {
    const { t: consoleFinalText } = useConsoleTranslator();
    const label = suppliedLabel ?? consoleFinalText('console.api_c8e5998f');
    const described = usePageActions()[action];
    const [open, setOpen] = useState(defaultOpen);
    const region = useId();

    if (described === undefined) {
        return null;
    }

    return (
        <div
            className="cbx-api-equivalent"
            data-api-equivalent={action}
            // Beside a submit button it sits in the button row; opened, it takes the row's
            // whole width rather than squeezing code into the space beside the button.
            style={{ minWidth: 0, flexBasis: open ? '100%' : undefined }}
        >
            <Button
                type="button"
                size="sm"
                variant="ghost"
                aria-expanded={open}
                aria-controls={region}
                onClick={() => setOpen((current) => !current)}
            >
                <Icon name="code" className="w-4 h-4" />
                {label}
            </Button>

            {open && (
                <div
                    id={region}
                    className="mt-2 rounded-lg p-3"
                    style={{ border: '1px solid var(--border)', background: 'var(--surface)' }}
                >
                    <ApiEquivalentBody action={described} values={values} />
                </div>
            )}
        </div>
    );
}

/** The four snippets for one action, with its method, path and scope above them. */
export function ApiEquivalentBody({
    action,
    values,
}: {
    action: ApiAction;
    values?: SnippetValues;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const snippets = useMemo(
        () => apiSnippets(action, values, consoleFinalText, consoleRich),
        [action, values, consoleFinalText, consoleRich],
    );

    return (
        <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <Pill tone={action.danger === 'read' ? 'neutral' : 'info'}>{action.method}</Pill>
                <code className="mono break-all">
                    {action.base ?? '/api/v1'}
                    {action.path}
                </code>
                <span style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console.needs_part1_5e67f8f1', {
                        part1: <code className="mono">{action.scope}</code>,
                    })}
                </span>
                {(action.danger === 'destructive' || action.danger === 'critical') && (
                    <Pill tone="warning">
                        {consoleFinalText(
                            action.danger === 'critical'
                                ? 'console.agent_risk_critical'
                                : 'console.agent_risk_destructive',
                        )}
                    </Pill>
                )}
            </div>
            <p className="mt-1 mb-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {action.summary}
            </p>

            <SnippetTabs
                snippets={snippets}
                label={consoleFinalText('console._part1_as_code_54016cd4', { part1: action.name })}
            />
        </div>
    );
}

/** The four doors, as snippets. Exported for the tests. */
export function apiSnippets(
    action: ApiAction,
    values: SnippetValues | undefined,
    tc: Translator['t'],
    rich: Translator['rich'],
): CodeSnippet[] {
    return [
        {
            id: 'curl',
            label: 'curl',
            code: curl(action, values),
            note: rich('console.api_curl_credential_note', {
                variable: <code className="mono">${tokenEnv(action.plane)}</code>,
            }),
        },
        {
            id: 'mcp',
            label: 'MCP',
            code: mcp(action, values),
            note: rich('console.api_mcp_tool_note', {
                tool: <code className="mono">{action.tool}</code>,
                endpoint: <code className="mono">/mcp</code>,
            }),
        },
        {
            id: 'cli',
            label: 'CLI',
            code: cli(action, values),
            note: action.cliShipped
                ? tc('console.the_cbox_cli_signed_in_with_cbox_login_88433b30')
                : tc('console.not_in_the_cbox_cli_yet_this_is_the_command__48b39c79'),
        },
        {
            id: 'sdk',
            label: 'id-js',
            code: sdk(action, values),
            install: 'npm i @cboxdk/id-js',
            docs: 'https://www.npmjs.com/package/@cboxdk/id-js',
            note: action.sdkPreview
                ? tc('console.preview_this_call_is_not_in_the_published_sd_15302df3')
                : tc('console.server_code_only_the_client_holds_a_manageme_d8e5b217'),
        },
    ];
}

/**
 * Every action the page hosts, behind one "</> API" button in the console's top bar — so a
 * page whose forms nobody wired an inline disclosure into still answers "how do I script
 * this?", whatever its own header looks like.
 */
export function PageApiEquivalents() {
    const { t: consoleT } = useConsoleTranslator();
    const actions = usePageActions();
    const list = Object.values(actions);
    const [open, setOpen] = useState(false);
    const [chosen, setChosen] = useState<string | null>(null);
    const current = list.find((action) => action.name === chosen) ?? list[0];

    if (current === undefined) {
        return null;
    }

    return (
        <>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => setOpen(true)}
                data-page-api-equivalents={list.length}
                aria-label={consoleT('console.api_do_this_page_s_actions_from_code_3cf98d0c')}
            >
                <Icon name="code" className="w-4 h-4" />
                <span className="hidden sm:inline">API</span>
            </Button>

            <Dialog
                open={open}
                onOpenChange={setOpen}
                size="lg"
                title={consoleT('console.do_this_from_code_d9ead9ce')}
                description={consoleT(
                    'console.everything_on_this_page_is_also_a_rest_endpoint_an_mcp_28d51f03',
                )}
            >
                {list.length > 1 && (
                    <div className="mb-4">
                        <Select
                            aria-label={consoleT('console.action_64cff131')}
                            value={current.name}
                            onValueChange={setChosen}
                            options={list.map((action) => ({
                                value: action.name,
                                label: action.name,
                                hint: `${action.method} ${action.path}`,
                            }))}
                        />
                    </div>
                )}

                <ApiEquivalentBody action={current} />
            </Dialog>
        </>
    );
}
