import { useTranslator } from '@/i18n';
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
    presetPresentation,
    presetById,
    presetScopes,
    riskLabel,
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
    const { t: tc } = useTranslator();

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
                            {tc('console.a_management_key_for_one_agent_it_can_do_onl_0071f25b')}
                            <Link href={urls.approvals}>
                                {tc('console.approvals_page_e9d11be4')}
                            </Link>
                            .
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
                <Panel title={tc('console.the_agent_750a4ad2')}>
                    <div className="space-y-4">
                        <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={tc('console.claude_code_246ef8c1')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={tc('console.what_it_is_for_1ad944c4')}
                            hint={tc(
                                'console.optional_shown_beside_it_on_the_agents_page_1ce8220d',
                            )}
                            error={form.errors.description}
                        >
                            <Textarea
                                name="description"
                                rows={2}
                                placeholder={tc(
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
                    title={tc('console.what_it_can_do_55e8e93f')}
                    description={tc(
                        'console.start_from_a_preset_then_adjust_each_scope_i_60006824',
                    )}
                >
                    <div className="space-y-5">
                        <fieldset>
                            <legend className="label">{tc('console.preset_7252e7ce')}</legend>
                            <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                {PRESETS.map((preset) => {
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
                                                {presetPresentation(preset.id, tc).label}
                                            </span>
                                            <span
                                                className="mt-1 block text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {presetPresentation(preset.id, tc).description}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>

                        <p className="text-sm flex items-center gap-2 flex-wrap" aria-live="polite">
                            <span>
                                {form.data.scopes.length}{' '}
                                {form.data.scopes.length === 1
                                    ? tc('console.scope_5f161c91')
                                    : tc('console.scopes_56b3669c')}
                                {current === null ? tc('console.custom_488253ad') : ''}
                            </span>
                            <span aria-hidden="true" style={{ color: 'var(--faint)' }}>
                                ·
                            </span>
                            <span>{tc('console.highest_risk_249ac63f')}</span>
                            <Badge tone={RISK_TONE[risk]}>{riskLabel(risk, tc)}</Badge>
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
                    title={tc('console.approval_147fb813')}
                    description={
                        <>
                            {tc('console.which_of_its_actions_wait_for_a_person_the_a_1cb6cf78')}{' '}
                            <Link href={urls.approvals}>
                                {tc('console.approvals_page_e9d11be4')}
                            </Link>
                            {tc('console.and_it_repeats_the_request_once_56e33e2d')}
                        </>
                    }
                >
                    <div className="space-y-5">
                        <RadioGroup
                            label={tc('console.ask_before_67dda6c4')}
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
                                {tc(
                                    'console.approvals_cover_the_management_api_s_actions_65e224f1',
                                )}{' '}
                                <span className="mono">
                                    {unheld.map((scope) => scope.value).join(', ')}
                                </span>
                                .
                            </p>
                        )}
                    </div>
                </Panel>

                <Panel
                    title={tc('console.expiry_6956d814')}
                    description={tc(
                        'console.an_agent_is_usually_for_a_piece_of_work_rota_bc3983fd',
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
                        {tc('console.create_agent_key_fcab7a18')}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={urls.index}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

function BackLink({ href }: { href: string }) {
    const { t: tc } = useTranslator();

    return <Breadcrumb href={href} label={tc('console.agents_279b44d2')} />;
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
    const { t: tc } = useTranslator();
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
                                                    {riskLabel(scope.risk, tc)}
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
    const { t: tc } = useTranslator();

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
                label={tc('console.also_ask_before_these_actions_78d86299')}
                hint={tc('console.whatever_their_risk_for_example_the_one_acti_af03fab8')}
                error={error}
            >
                <Input
                    type="search"
                    placeholder={tc('console.search_actions_bf47a406')}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </Field>

            <output className="block mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {shown.length}
                {tc('console.of_a4282e4b')}
                {actions.length}
                {tc('console.actions_shown_98dc4908')}
                {selected.length}
                {tc('console.chosen_ab91342d')}
            </output>

            {selected.length > 0 && (
                <ul
                    className="mt-2 flex flex-wrap gap-1.5"
                    aria-label={tc('console.actions_that_always_wait_f91ebe6b')}
                >
                    {selected.map((name) => (
                        <li key={name}>
                            <button
                                type="button"
                                className="inline-flex"
                                onClick={() => onChange(selected.filter((s) => s !== name))}
                                aria-label={tc('console.stop_asking_before_value0_ac5f200f', {
                                    value0: name,
                                })}
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
                        {tc('console.no_action_matches_03ca7c19')}
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
                                            {riskLabel(action.danger, tc)}
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
    const { t: tc } = useTranslator();

    const command = mcpUrl === null ? null : claudeCode(mcpUrl, value).code;

    return (
        <>
            <BackLink href={urls.index} />

            <div className="mt-2">
                <PageHeader
                    title={tc('console.value0_is_ready_753017c8', { value0: name })}
                    description={tc(
                        'console.copy_the_key_now_and_hand_it_to_the_agent_on_fb10c803',
                    )}
                />
            </div>

            <div className="mt-6 space-y-6" style={{ maxWidth: '48rem' }}>
                <RevealedKey value={value}>
                    {command !== null && (
                        <div className="mt-5">
                            <p className="label">{tc('console.connect_claude_code_dad252a1')}</p>
                            <div className="mt-1.5 flex items-start gap-2">
                                <code
                                    className="mono text-xs break-all flex-1 rounded-lg px-3 py-2.5"
                                    style={{ background: 'var(--card)' }}
                                >
                                    {command}
                                </code>
                                <CopyButton
                                    value={command}
                                    label={tc('console.copy_command_9a01feec')}
                                />
                            </div>
                        </div>
                    )}
                </RevealedKey>

                <div className="flex items-center gap-3 flex-wrap">
                    <Button asChild variant="primary">
                        <Link href={urls.index}>{tc('console.done_11a6767d')}</Link>
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={urls.connect}>
                            {tc('console.other_clients_cursor_vs_code_claude_desktop_5232478a')}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

CreateAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
