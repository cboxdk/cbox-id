import { Link, router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, Checkbox, EmptyState, Icon, Input, type LinkTab, Pill, Select } from '@/ui';
import { type RadarMode, RadarFrame } from './frame';

type Verdict = 'allow' | 'challenge' | 'block';

interface Builtin {
    key: string;
    name: string;
    description: string;
    applies_to: 'all' | 'sign_in' | 'sign_up';
    enabled: boolean;
    action: Verdict;
    threshold: number | null;
    threshold_unit: string | null;
    threshold_min: number | null;
    threshold_max: number | null;
}

interface Rule {
    id: string;
    name: string;
    description: string | null;
    position: number;
    enabled: boolean;
    applies_to: 'all' | 'sign_in' | 'sign_up';
    action: Verdict;
    summary: string;
    href: string;
    destroyHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    tabs: LinkTab[];
    mode: RadarMode;
    settings: { builtin_rules: Builtin[] };
    settingsHref: string;
    rules: Rule[];
    createHref: string;
    orderHref: string;
}>;

const VERDICT_TONE = { allow: 'success', challenge: 'warning', block: 'destructive' } as const;
const FLOW = { all: 'Sign-in and sign-up', sign_in: 'Sign-in', sign_up: 'Sign-up' } as const;
const ACTIONS: { value: Verdict; label: string }[] = [
    { value: 'allow', label: 'Allow' },
    { value: 'challenge', label: 'Challenge' },
    { value: 'block', label: 'Block' },
];

/**
 * The rules, in the order Radar asks them: the deny list, the allow list, YOUR rules top to
 * bottom (the first that matches decides), then every built-in rule (the strictest that fires
 * decides).
 */
export default function RadarRules({
    help,
    tabs,
    mode,
    settings,
    settingsHref,
    rules,
    createHref,
    orderHref,
}: Props) {
    const move = (index: number, by: -1 | 1): void => {
        const ids = rules.map((rule) => rule.id);
        const [moved] = ids.splice(index, 1);

        if (moved === undefined) {
            return;
        }

        ids.splice(index + by, 0, moved);
        router.put(orderHref, { ruleIds: ids }, { preserveScroll: true });
    };

    return (
        <RadarFrame
            help={help}
            tabs={tabs}
            mode={mode}
            description="Evaluated in order: the deny list, the allow list, your rules from the top (the first that matches decides), then the built-in rules (the strictest that fires decides). Nothing matched is an allow."
            actions={
                <Button asChild variant="primary">
                    <Link href={createHref}>
                        <Icon name="plus" className="w-4 h-4" />
                        New rule
                    </Link>
                </Button>
            }
        >
            <section className="mt-6">
                <h2 className="text-sm font-semibold">Your rules</h2>
                {rules.length === 0 ? (
                    <div
                        className="mt-3 rounded-xl border"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <EmptyState
                            icon="sliders"
                            equivalent="radar.rules.create"
                            title="No rules of your own"
                            description="Write one to allow, challenge or block on what you know about your users — challenge sign-ins from outside the countries you operate in, allow your office network past the velocity rules."
                        />
                    </div>
                ) : (
                    <ol className="mt-3 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        {rules.map((rule, index) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center gap-3 p-4"
                                style={
                                    index < rules.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <span
                                    className="mono text-xs w-6"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {rule.position}
                                </span>
                                <Pill tone={VERDICT_TONE[rule.action]}>{rule.action}</Pill>
                                <Link
                                    href={rule.href}
                                    className="font-medium text-sm hover:underline"
                                >
                                    {rule.name}
                                </Link>
                                {!rule.enabled && <Badge>off</Badge>}
                                <Badge tone="info">{FLOW[rule.applies_to]}</Badge>
                                <span
                                    className="text-xs basis-full sm:basis-auto"
                                    style={{ color: 'var(--muted)' }}
                                >
                                    {rule.summary}
                                </span>
                                <span className="ml-auto flex items-center gap-1">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                        aria-label={`Move ${rule.name} up`}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={index === rules.length - 1}
                                        onClick={() => move(index, 1)}
                                        aria-label={`Move ${rule.name} down`}
                                    >
                                        ↓
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <BuiltinRules builtins={settings.builtin_rules} href={settingsHref} />
        </RadarFrame>
    );
}

interface BuiltinSetting {
    enabled: boolean;
    action: Verdict;
    threshold: number | null;
}

function BuiltinRules({ builtins, href }: { builtins: Builtin[]; href: string }) {
    const form = useForm({
        builtinRules: Object.fromEntries(
            builtins.map((rule) => [
                rule.key,
                { enabled: rule.enabled, action: rule.action, threshold: rule.threshold },
            ]),
        ) as Record<string, BuiltinSetting>,
    });

    const settingOf = (rule: Builtin): BuiltinSetting =>
        form.data.builtinRules[rule.key] ?? {
            enabled: rule.enabled,
            action: rule.action,
            threshold: rule.threshold,
        };

    const update = (rule: Builtin, change: Partial<BuiltinSetting>) =>
        form.setData('builtinRules', {
            ...form.data.builtinRules,
            [rule.key]: { ...settingOf(rule), ...change },
        });

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">Built-in rules</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                Asked after your own rules. Every one that fires is recorded; the strictest decides.
            </p>
            <form
                className="mt-4 grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                {builtins.map((rule) => {
                    const current = settingOf(rule);

                    return (
                        <div
                            key={rule.key}
                            className="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-center pb-4"
                            style={{ borderBottom: '1px solid var(--border)' }}
                        >
                            <Checkbox
                                checked={current.enabled}
                                onCheckedChange={(checked) => update(rule, { enabled: checked })}
                                label={`${rule.name} · ${FLOW[rule.applies_to]}`}
                                hint={rule.description}
                            />
                            {rule.threshold_unit !== null ? (
                                <label
                                    className="flex items-center gap-2 text-xs"
                                    style={{ color: 'var(--muted)' }}
                                >
                                    <Input
                                        type="number"
                                        min={rule.threshold_min ?? undefined}
                                        max={rule.threshold_max ?? undefined}
                                        style={{ maxWidth: '7rem' }}
                                        value={current.threshold ?? ''}
                                        aria-label={`${rule.name} threshold`}
                                        onChange={(event) =>
                                            update(rule, {
                                                threshold:
                                                    Number.parseInt(event.target.value, 10) || null,
                                            })
                                        }
                                    />
                                    {rule.threshold_unit}
                                </label>
                            ) : (
                                <span />
                            )}
                            <Select
                                value={current.action}
                                onValueChange={(action) => update(rule, { action })}
                                options={ACTIONS}
                                aria-label={`${rule.name} action`}
                            />
                        </div>
                    );
                })}
                {typeof form.errors.builtinRules === 'string' && (
                    <p role="alert" className="field-error">
                        {form.errors.builtinRules}
                    </p>
                )}
                <div>
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        disabled={!form.isDirty}
                    >
                        Save built-in rules
                    </Button>
                </div>
            </form>
        </section>
    );
}

RadarRules.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
