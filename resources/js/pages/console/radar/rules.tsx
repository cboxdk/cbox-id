import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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
const FLOW = (tc: Translator['t']) =>
    ({
        all: tc('console.sign_in_and_sign_up_b9773c35'),
        sign_in: 'Sign-in',
        sign_up: 'Sign-up',
    }) as const;
const ACTIONS = (tc: Translator['t']): { value: Verdict; label: string }[] => [
    { value: 'allow', label: tc('console.allow_e213c161') },
    { value: 'challenge', label: tc('console.challenge_27cf1792') },
    { value: 'block', label: tc('console.block_211d0bb8') },
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
    const { t: tc } = useTranslator();

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
            description={tc('console.evaluated_in_order_the_deny_list_the_allow_l_00db2fea')}
            actions={
                <Button asChild variant="primary">
                    <Link href={createHref}>
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.new_rule_99cd24ef')}
                    </Link>
                </Button>
            }
        >
            <section className="mt-6">
                <h2 className="text-sm font-semibold">{tc('console.your_rules_a6b37899')}</h2>
                {rules.length === 0 ? (
                    <div
                        className="mt-3 rounded-xl border"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <EmptyState
                            icon="sliders"
                            equivalent="radar.rules.create"
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_rule_99cd24ef')}
                                    </Link>
                                </Button>
                            }
                            title={tc('console.no_rules_of_your_own_29314503')}
                            description={tc(
                                'console.write_one_to_allow_challenge_or_block_on_wha_9a9a03a4',
                            )}
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
                                {!rule.enabled && <Badge>{tc('console.off_b4dc66dd')}</Badge>}
                                <Badge tone="info">{FLOW(tc)[rule.applies_to]}</Badge>
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
                                        aria-label={tc('console.move_value0_up_6ca6b733', {
                                            value0: rule.name,
                                        })}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={index === rules.length - 1}
                                        onClick={() => move(index, 1)}
                                        aria-label={tc('console.move_value0_down_5bdbbcdd', {
                                            value0: rule.name,
                                        })}
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
    const { t: tc } = useTranslator();

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
            <h2 className="text-sm font-semibold">{tc('console.built_in_rules_9d664821')}</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                {tc('console.asked_after_your_own_rules_every_one_that_fi_8f7f4b96')}
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
                                label={`${rule.name} · ${FLOW(tc)[rule.applies_to]}`}
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
                                        aria-label={tc('console.value0_threshold_956b362e', {
                                            value0: rule.name,
                                        })}
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
                                options={ACTIONS(tc)}
                                aria-label={tc('console.value0_action_b4d85f30', {
                                    value0: rule.name,
                                })}
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
                        {tc('console.save_built_in_rules_b12612af')}
                    </Button>
                </div>
            </form>
        </section>
    );
}

RadarRules.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
