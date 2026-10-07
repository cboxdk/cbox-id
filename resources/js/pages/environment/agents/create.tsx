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
    urls: { store: string; index: string; connect: string };
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
                    description="A management key for one agent. It can do only what its scopes allow, and the actions you choose wait for your approval — on your phone, or on the Approvals page."
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
                <Panel title="The agent">
                    <div className="space-y-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Claude Code"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field
                            label="What it is for"
                            hint="Optional. Shown beside it on the Agents page."
                            error={form.errors.description}
                        >
                            <Textarea
                                name="description"
                                rows={2}
                                placeholder="Triage support tickets in the Acme workspace"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                </Panel>

                <Panel
                    title="What it can do"
                    description="Start from a preset, then adjust. Each scope is marked with the most harmful thing it allows."
                >
                    <div className="space-y-5">
                        <fieldset>
                            <legend className="label">Preset</legend>
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
                                {form.data.scopes.length}{' '}
                                {form.data.scopes.length === 1 ? 'scope' : 'scopes'}
                                {current === null ? ' · custom' : ''}
                            </span>
                            <span aria-hidden="true" style={{ color: 'var(--faint)' }}>
                                ·
                            </span>
                            <span>Highest risk</span>
                            <Badge tone={RISK_TONE[risk]}>{RISK_LABEL[risk]}</Badge>
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
                    title="Approval"
                    description="Which of its actions wait for a person. The agent gets a 202 with a short code, you approve on your phone or on the Approvals page, and it repeats the request — once."
                >
                    <div className="space-y-5">
                        <RadioGroup
                            label="Ask before"
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
                                Approvals cover the management API's actions. These scopes are
                                served by endpoints that are not actions yet, so they run without
                                asking:{' '}
                                <span className="mono">
                                    {unheld.map((scope) => scope.value).join(', ')}
                                </span>
                                .
                            </p>
                        )}
                    </div>
                </Panel>

                <Panel
                    title="Expiry"
                    description="An agent is usually for a piece of work. Rotate the key, or create another, when it runs out."
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
                        Create agent key
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={urls.index}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

function BackLink({ href }: { href: string }) {
    return (
        <Link
            href={href}
            className="text-sm inline-flex items-center gap-1"
            style={{ color: 'var(--muted-foreground)' }}
        >
            <Icon name="chevron" className="w-3.5 h-3.5" style={{ transform: 'rotate(90deg)' }} />
            Agents
        </Link>
    );
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
                                                    {RISK_LABEL[scope.risk]}
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
                label="Also ask before these actions"
                hint="Whatever their risk — for example the one action you want to watch."
                error={error}
            >
                <Input
                    type="search"
                    placeholder="Search actions"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </Field>

            <output className="block mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {shown.length} of {actions.length} actions shown · {selected.length} chosen
            </output>

            {selected.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-1.5" aria-label="Actions that always wait">
                    {selected.map((name) => (
                        <li key={name}>
                            <button
                                type="button"
                                className="inline-flex"
                                onClick={() => onChange(selected.filter((s) => s !== name))}
                                aria-label={`Stop asking before ${name}`}
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
                        No action matches.
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
                                            {RISK_LABEL[action.danger]}
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
    const command = mcpUrl === null ? null : claudeCode(mcpUrl, value).code;

    return (
        <>
            <BackLink href={urls.index} />

            <div className="mt-2">
                <PageHeader
                    title={`${name} is ready`}
                    description="Copy the key now and hand it to the agent. Only a hash is kept, so this is the only time it is shown."
                />
            </div>

            <div className="mt-6 space-y-6" style={{ maxWidth: '48rem' }}>
                <RevealedKey value={value}>
                    {command !== null && (
                        <div className="mt-5">
                            <p className="label">Connect Claude Code</p>
                            <div className="mt-1.5 flex items-start gap-2">
                                <code
                                    className="mono text-xs break-all flex-1 rounded-lg px-3 py-2.5"
                                    style={{ background: 'var(--card)' }}
                                >
                                    {command}
                                </code>
                                <CopyButton value={command} label="Copy command" />
                            </div>
                        </div>
                    )}
                </RevealedKey>

                <div className="flex items-center gap-3 flex-wrap">
                    <Button asChild variant="primary">
                        <Link href={urls.index}>Done</Link>
                    </Button>
                    <Button asChild variant="secondary">
                        <Link href={urls.connect}>
                            Other clients: Cursor, VS Code, Claude Desktop
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

CreateAgent.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
