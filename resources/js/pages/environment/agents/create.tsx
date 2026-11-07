import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import {
    type AgentAction,
    type AgentScope,
    type ApprovalLevel,
    groupScopes,
    highestRisk,
    matchingPreset,
    type PresetId,
    PRESETS,
    localizedPresets,
    presetById,
    presetScopes,
    RISK_LABEL,
    RISK_TONE,
    unheldScopes,
} from '@/lib/agents';
import { claudeCode } from '@/lib/mcpSnippets';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    CopyButton,
    ExpiryField,
    Field,
    Icon,
    Input,
    type KeyLifetimeOption,
    PageHeader,
    Panel,
    RadioGroup,
    RevealedKey,
    Textarea,
} from '@/ui';

type Props = PageProps<{
    environmentId: string;
    scopes: AgentScope[];
    actions: AgentAction[];
    approvalLevels: { value: ApprovalLevel; label: string; hint: string }[];
    lifetimes: KeyLifetimeOption[];
    defaults: { name: string; preset: PresetId | null; lifetime: string };
    mcpUrl: string | null;
    urls: { store: string; index: string; connect: string; approvals: string };
    help: HelpContent;
}>;

export default function CreateAgent({
    environmentId,
    scopes,
    actions,
    approvalLevels,
    lifetimes,
    defaults,
    mcpUrl,
    urls,
    help,
}: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const { freshKey, freshKeyName } = usePage().flash;
    const initial = presetById(defaults.preset ?? 'read-only') ?? PRESETS[0];

    const form = useForm({
        environment: environmentId,
        name: defaults.name,
        description: '',
        scopes: presetScopes(initial.id, scopes),
        approval: initial.approval as ApprovalLevel,
        approvalActions: [] as string[],
        expires: defaults.lifetime,
        expiresOn: '',
    });

    if (freshKey !== undefined) {
        return (
            <Created
                name={freshKeyName ?? form.data.name}
                value={freshKey}
                mcpUrl={mcpUrl}
                urls={urls}
            />
        );
    }

    const applyPreset = (id: PresetId): void => {
        const preset = presetById(id);

        if (preset !== null) {
            form.setData((data) => ({
                ...data,
                scopes: presetScopes(id, scopes),
                approval: preset.approval,
            }));
        }
    };

    const current = matchingPreset(form.data.scopes, scopes);
    const risk = highestRisk(form.data.scopes, scopes);
    const unheld = unheldScopes(form.data.scopes, scopes);

    return (
        <>
            <BackLink href={urls.index} />

            <div className="mt-2">
                <PageHeader
                    help={help}
                    description={
                        <>
                            {consoleRich(
                                'console.a_management_key_for_one_agent_it_can_do_only_what_its_e35f0a5f',
                                {
                                    part1: (
                                        <Link href={urls.approvals}>
                                            {consoleT('console.approvals_page_e9d11be4')}
                                        </Link>
                                    ),
                                },
                            )}
                        </>
                    }
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '48rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(urls.store, { preserveScroll: true });
                }}
            >
                <Panel title={consoleT('console.the_agent_750a4ad2')}>
                    <div className="space-y-4">
                        <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={consoleUiText('console.claude_code_246ef8c1')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={consoleT('console.what_it_is_for_1ad944c4')}
                            hint={consoleT(
                                'console.optional_shown_beside_it_on_the_agents_page_1ce8220d',
                            )}
                            error={form.errors.description}
                        >
                            <Textarea
                                name="description"
                                rows={2}
                                placeholder={consoleUiText(
                                    'console.triage_support_tickets_in_the_acme_workspace_535bad63',
                                )}
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.what_it_can_do_55e8e93f')}
                    description={consoleT(
                        'console.start_from_a_preset_then_adjust_each_scope_is_marked_wi_60006824',
                    )}
                >
                    <div className="space-y-5">
                        <fieldset>
                            <legend className="label">{consoleT('console.preset_7252e7ce')}</legend>
                            <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                {localizedPresets(consoleT).map((preset) => {
                                    const on = current === preset.id;

                                    return (
                                        <button
                                            key={preset.id}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => applyPreset(preset.id)}
                                            className="rounded-lg border px-3.5 py-3 text-left"
                                            style={{
                                                borderColor: on
                                                    ? 'var(--primary)'
                                                    : 'var(--border)',
                                                background: on ? 'var(--accent-soft)' : undefined,
                                            }}
                                        >
                                            <span className="flex items-center gap-1.5 text-sm font-semibold">
                                                {on && <Icon name="check" className="w-4 h-4" />}
                                                {preset.label}
                                            </span>
                                            <span
                                                className="mt-1 block text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {preset.description}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>

                        <p className="text-sm flex items-center gap-2 flex-wrap" aria-live="polite">
                            <span>
                                {consoleText('console.selected_permissions_count', {
                                    count: form.data.scopes.length,
                                })}
                                {current === null ? consoleText('console._custom_488253ad') : ''}
                            </span>
                            <span aria-hidden="true" style={{ color: 'var(--faint)' }}>
                                ·
                            </span>
                            <span>{consoleT('console.highest_risk_249ac63f')}</span>
                            <Badge tone={RISK_TONE[risk]}>{RISK_LABEL(consoleT)[risk]}</Badge>
                        </p>

                        <ScopePicker
                            scopes={scopes}
                            selected={form.data.scopes}
                            onChange={(next) => form.setData('scopes', next)}
                        />

                        {form.errors.scopes !== undefined && (
                            <p className="field-error" role="alert">
                                {form.errors.scopes}
                            </p>
                        )}
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.approval_147fb813')}
                    description={
                        <>
                            {consoleRich(
                                'console.which_of_its_actions_wait_for_a_person_the_agent_gets_a_e72df5dd',
                                {
                                    part1: ' ',
                                    part2: (
                                        <Link href={urls.approvals}>
                                            {consoleT('console.approvals_page_e9d11be4')}
                                        </Link>
                                    ),
                                },
                            )}
                        </>
                    }
                >
                    <div className="space-y-5">
                        <RadioGroup
                            label={consoleT('console.ask_before_67dda6c4')}
                            name="approval"
                            value={form.data.approval}
                            onValueChange={(approval) => form.setData('approval', approval)}
                            options={approvalLevels.map((level) => ({
                                value: level.value,
                                label: level.label,
                                hint: level.hint,
                            }))}
                        />
                        {form.errors.approval !== undefined && (
                            <p className="field-error" role="alert">
                                {form.errors.approval}
                            </p>
                        )}

                        <ActionPicker
                            actions={actions}
                            selected={form.data.approvalActions}
                            onChange={(next) => form.setData('approvalActions', next)}
                            error={form.errors.approvalActions}
                        />

                        {unheld.length > 0 && form.data.approval !== 'none' && (
                            <p
                                className="text-xs rounded-lg px-3 py-2.5"
                                style={{
                                    background: 'var(--surface-2)',
                                    color: 'var(--muted-foreground)',
                                }}
                            >
                                {consoleRich(
                                    'console.approvals_cover_the_management_api_s_actions_these_scop_3eade41d',
                                    {
                                        part1: ' ',
                                        part2: (
                                            <span className="mono">
                                                {unheld.map((scope) => scope.value).join(', ')}
                                            </span>
                                        ),
                                    },
                                )}
                            </p>
                        )}
                    </div>
                </Panel>

                <Panel
                    title={consoleT('console.expiry_6956d814')}
                    description={consoleT(
                        'console.an_agent_is_usually_for_a_piece_of_work_rotate_the_key_bc3983fd',
                    )}
                >
                    <ExpiryField
                        lifetimes={lifetimes}
                        lifetime={form.data.expires}
                        onLifetimeChange={(expires) => form.setData('expires', expires)}
                        date={form.data.expiresOn}
                        onDateChange={(expiresOn) => form.setData('expiresOn', expiresOn)}
                        lifetimeError={form.errors.expires}
                        dateError={form.errors.expiresOn}
                    />
                </Panel>

                <div className="flex items-center gap-3">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.create_agent_key_fcab7a18')}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={urls.index}>{consoleT('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

function BackLink({ href }: { href: string }) {
    const { t: consoleT } = useConsoleTranslator();
    return <Breadcrumb href={href} label={consoleT('console.agents_279b44d2')} />;
}

/**
 * Grouped by resource, read before write, each scope wearing its risk. A group's header
 * toggles the whole resource — the common case is "all of users", not one box at a time.
 */
function ScopePicker({
    scopes,
    selected,
    onChange,
}: {
    scopes: AgentScope[];
    selected: string[];
    onChange: (next: string[]) => void;
}) {
    const { t: consoleT } = useConsoleTranslator();
    const groups = useMemo(() => groupScopes(scopes), [scopes]);

    const toggle = (value: string, on: boolean): void => {
        onChange(on ? [...selected, value] : selected.filter((s) => s !== value));
    };

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {groups.map((group) => {
                const values = group.scopes.map((scope) => scope.value);
                const chosen = values.filter((value) => selected.includes(value)).length;

                return (
                    <fieldset
                        key={group.resource}
                        className="rounded-lg border px-3.5 py-3"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <legend className="sr-only">{group.label}</legend>
                        <div className="flex items-center gap-2 mb-2">
                            <Checkbox
                                checked={
                                    chosen === 0
                                        ? false
                                        : chosen === values.length
                                          ? true
                                          : 'indeterminate'
                                }
                                onCheckedChange={(on) =>
                                    onChange(
                                        on
                                            ? [...new Set([...selected, ...values])]
                                            : selected.filter((s) => !values.includes(s)),
                                    )
                                }
                                label={<span className="text-sm font-semibold">{group.label}</span>}
                            />
                        </div>
                        <div className="space-y-2 pl-6">
                            {group.scopes.map((scope) => (
                                <Checkbox
                                    key={scope.value}
                                    checked={selected.includes(scope.value)}
                                    onCheckedChange={(on) => toggle(scope.value, on)}
                                    hint={scope.description ?? undefined}
                                    label={
                                        <span className="flex items-center gap-2 flex-wrap">
                                            <span className="text-sm">{scope.label}</span>
                                            <span
                                                className="mono text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {scope.value}
                                            </span>
                                            {scope.risk !== 'read' && (
                                                <Badge tone={RISK_TONE[scope.risk]}>
                                                    {RISK_LABEL(consoleT)[scope.risk]}
                                                </Badge>
                                            )}
                                        </span>
                                    }
                                />
                            ))}
                        </div>
                    </fieldset>
                );
            })}
        </div>
    );
}

/** Named actions that always wait, whatever the level — searched, because there are many. */
function ActionPicker({
    actions,
    selected,
    onChange,
    error,
}: {
    actions: AgentAction[];
    selected: string[];
    onChange: (next: string[]) => void;
    error?: string;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [query, setQuery] = useState('');
    const needle = query.trim().toLowerCase();
    const shown = actions.filter(
        (action) =>
            needle === '' ||
            action.name.toLowerCase().includes(needle) ||
            action.summary.toLowerCase().includes(needle),
    );

    return (
        <div>
            <Field
                label={consoleT('console.also_ask_before_these_actions_78d86299')}
                hint={consoleT(
                    'console.whatever_their_risk_for_example_the_one_action_you_want_af03fab8',
                )}
                error={error}
            >
                <Input
                    type="search"
                    placeholder={consoleUiText('console.search_actions_bf47a406')}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </Field>

            <output className="block mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {consoleRich('console._part1_of_part2_actions_shown_part3_chosen_69e741ea', {
                    part1: shown.length,
                    part2: actions.length,
                    part3: selected.length,
                })}
            </output>

            {selected.length > 0 && (
                <ul
                    className="mt-2 flex flex-wrap gap-1.5"
                    aria-label={consoleT('console.actions_that_always_wait_f91ebe6b')}
                >
                    {selected.map((name) => (
                        <li key={name}>
                            <button
                                type="button"
                                className="inline-flex"
                                onClick={() => onChange(selected.filter((s) => s !== name))}
                                aria-label={consoleFinalText(
                                    'console.stop_asking_before_part1_226275ed',
                                    { part1: name },
                                )}
                            >
                                <Badge tone="info">
                                    <span className="mono">{name}</span> ×
                                </Badge>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div
                className="mt-2 rounded-lg border overflow-y-auto space-y-1.5 px-3 py-2"
                style={{ borderColor: 'var(--border)', maxHeight: '14rem' }}
            >
                {shown.length === 0 ? (
                    <p className="text-sm py-1" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT('console.no_action_matches_03ca7c19')}
                    </p>
                ) : (
                    shown.map((action) => (
                        <Checkbox
                            key={action.name}
                            checked={selected.includes(action.name)}
                            onCheckedChange={(on) =>
                                onChange(
                                    on
                                        ? [...selected, action.name]
                                        : selected.filter((s) => s !== action.name),
                                )
                            }
                            hint={action.summary}
                            label={
                                <span className="flex items-center gap-2 flex-wrap">
                                    <span className="mono text-sm">{action.name}</span>
                                    {action.danger !== 'read' && (
                                        <Badge tone={RISK_TONE[action.danger]}>
                                            {RISK_LABEL(consoleT)[action.danger]}
                                        </Badge>
                                    )}
                                </span>
                            }
                        />
                    ))
                )}
            </div>
        </div>
    );
}

/**
 * The key, once — and the command to use it, with the key already in it. This is the one
 * moment the two can be copied together; after it, only the command's shape is left.
 */
function Created({
    name,
    value,
    mcpUrl,
    urls,
}: {
    name: string;
    value: string;
    mcpUrl: string | null;
    urls: { index: string; connect: string };
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    const command = mcpUrl === null ? null : claudeCode(mcpUrl, value).code;

    return (
        <>
            <BackLink href={urls.index} />

            <div className="mt-2">
                <PageHeader
                    title={consoleFinalText('console._part1_is_ready_d6da1fe1', { part1: name })}
                    description={consoleT(
                        'console.copy_the_key_now_and_hand_it_to_the_agent_only_a_hash_i_fb10c803',
                    )}
                />
            </div>

            <div className="mt-6 space-y-6" style={{ maxWidth: '48rem' }}>
                <RevealedKey value={value}>
                    {command !== null && (
                        <div className="mt-5">
                            <p className="label">
                                {consoleT('console.connect_claude_code_dad252a1')}
                            </p>
                            <div className="mt-1.5 flex items-start gap-2">
                                <code
                                    className="mono text-xs break-all flex-1 rounded-lg px-3 py-2.5"
                                    style={{ background: 'var(--card)' }}
                                >
                                    {command}
                                </code>
                                <CopyButton
                                    value={command}
                                    label={consoleT('console.copy_command_9a01feec')}
                                />
                            </div>
                        </div>
                    )}
                </RevealedKey>

                <div className="flex items-center gap-3 flex-wrap">
                    <Button asChild variant="primary">
                        <Link href={urls.index}>{consoleT('console.done_11a6767d')}</Link>
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={urls.connect}>
                            {consoleT(
                                'console.other_clients_cursor_vs_code_claude_desktop_5232478a',
                            )}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

CreateAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
