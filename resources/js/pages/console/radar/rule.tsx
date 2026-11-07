import { useTranslator as usePublicTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    Field,
    Icon,
    Input,
    PageHeader,
    Select,
    Textarea,
} from '@/ui';

type Verdict = 'allow' | 'challenge' | 'block';
type Scope = 'all' | 'sign_in' | 'sign_up';

interface FieldOption {
    value: string;
    label: string;
    type: 'string' | 'number' | 'boolean' | 'ip' | 'country';
    operators: { value: string; label: string; list: boolean }[];
}

interface Condition {
    /** The editor's own handle for a row, so React keys it by identity and not by position. */
    key: string;
    field: string;
    operator: string;
    value: string;
}

interface Rule {
    id: string;
    name: string;
    description: string | null;
    position: number;
    enabled: boolean;
    applies_to: Scope;
    action: Verdict;
    conditions: {
        field: string;
        operator: string;
        value: string | number | boolean | (string | number)[];
    }[];
}

type Props = PageProps<{
    help: HelpContent;
    rule: Rule | null;
    fields: FieldOption[];
    actions: Verdict[];
    scopes: Scope[];
    submitHref: string;
    destroyHref: string | null;
    rulesHref: string;
}>;

const ACTION_LABEL = (t: Translator['t']): Record<Verdict, string> => ({
    allow: t('console.allow_skip_every_rule_after_this_one_a9cac725'),
    challenge: t('console.challenge_a_second_factor_on_sign_in_a_captcha_or_email_38e590f3'),
    block: t('console.block_refuse_with_a_generic_message_206a03e9'),
});
const SCOPE_LABEL = (t: Translator['t']): Record<Scope, string> => ({
    all: t('console.sign_in_and_sign_up_b9773c35'),
    sign_in: t('console.sign_in_only_527f76e3'),
    sign_up: t('console.sign_up_only_e179069e'),
});

let nextKey = 0;

function rowKey(): string {
    nextKey += 1;

    return `condition-${nextKey}`;
}

function text(value: Rule['conditions'][number]['value']): string {
    if (Array.isArray(value)) {
        return value.join(', ');
    }

    return String(value);
}

/**
 * One rule: WHEN every condition holds, THEN this action. A condition compares one fact
 * about the attempt with a value — nothing here is code, and an unknown fact matches nothing.
 */
export default function RadarRuleEditor({
    help,
    rule,
    fields,
    actions,
    scopes,
    submitHref,
    destroyHref,
    rulesHref,
}: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleStaticText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        name: rule?.name ?? '',
        description: rule?.description ?? '',
        action: rule?.action ?? ('challenge' as Verdict),
        appliesTo: rule?.applies_to ?? ('all' as Scope),
        enabled: rule?.enabled ?? true,
        conditions: (rule?.conditions.map((condition) => ({
            key: rowKey(),
            field: condition.field,
            operator: condition.operator,
            value: text(condition.value),
        })) ?? [{ key: rowKey(), field: 'country', operator: 'not_in', value: '' }]) as Condition[],
    });

    const fieldOf = (key: string): FieldOption | undefined =>
        fields.find((field) => field.value === key);

    const setCondition = (index: number, change: Partial<Condition>): void => {
        const next = form.data.conditions.map((condition, at) => {
            if (at !== index) {
                return condition;
            }

            const merged = { ...condition, ...change };
            const field = fieldOf(merged.field);

            // A field that does not take the chosen operator gets its first one.
            if (
                field !== undefined &&
                !field.operators.some((operator) => operator.value === merged.operator)
            ) {
                merged.operator = field.operators[0]?.value ?? '';
            }

            return merged;
        });

        form.setData('conditions', next);
    };

    return (
        <>
            <Breadcrumb href={rulesHref} label={consoleT('console.radar_rules_d0f99c71')} />

            <div className="mt-2">
                <PageHeader
                    help={help}
                    description={consoleT(
                        'console.when_every_condition_holds_radar_takes_this_rule_s_acti_1e685d20',
                    )}
                />
            </div>

            <form
                className="mt-6 grid gap-5 card p-5"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (rule === null) {
                        form.post(submitHref);
                    } else {
                        form.patch(submitHref);
                    }
                }}
            >
                <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        value={form.data.name}
                        maxLength={120}
                        placeholder={consoleUiText(
                            'console.challenge_sign_ins_from_outside_the_nordics_88b2de45',
                        )}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.why_it_exists_77890c5d')}
                    error={form.errors.description}
                    hint={consoleT('console.optional_for_whoever_reads_it_next_460bb779')}
                >
                    <Textarea
                        value={form.data.description}
                        maxLength={500}
                        rows={2}
                        onChange={(event) => form.setData('description', event.target.value)}
                    />
                </Field>

                <fieldset className="grid gap-3">
                    <legend className="text-sm font-semibold">
                        {consoleT('console.when_cf9c7aa2')}
                    </legend>
                    {form.data.conditions.map((condition, index) => {
                        const field = fieldOf(condition.field);
                        const operator = field?.operators.find(
                            (option) => option.value === condition.operator,
                        );

                        return (
                            <div
                                key={condition.key}
                                className="grid gap-2 sm:grid-cols-[1.4fr_1fr_1.4fr_auto] items-end"
                            >
                                <Field
                                    label={
                                        index === 0
                                            ? consoleText('console.fact_2558566c')
                                            : consoleText('console.and_6201111b')
                                    }
                                >
                                    <Select
                                        value={condition.field}
                                        onValueChange={(value) =>
                                            setCondition(index, { field: value })
                                        }
                                        options={fields.map((option) => ({
                                            value: option.value,
                                            label: option.label,
                                        }))}
                                    />
                                </Field>
                                <Field label={consoleT('console.is_f5985de1')}>
                                    <Select
                                        value={condition.operator}
                                        onValueChange={(value) =>
                                            setCondition(index, { operator: value })
                                        }
                                        options={(field?.operators ?? []).map((option) => ({
                                            value: option.value,
                                            label: option.label,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    label={
                                        operator?.list
                                            ? consoleText('console.values_comma_separated_6face72e')
                                            : consoleText('console.value_8e37953d')
                                    }
                                >
                                    {field?.type === 'boolean' ? (
                                        <Select
                                            value={
                                                condition.value === '' ? undefined : condition.value
                                            }
                                            onValueChange={(value) =>
                                                setCondition(index, { value })
                                            }
                                            options={[
                                                {
                                                    value: 'true',
                                                    label: consoleText('console.yes_8a798890'),
                                                },
                                                {
                                                    value: 'false',
                                                    label: consoleText('console.no_9390298f'),
                                                },
                                            ]}
                                        />
                                    ) : (
                                        <Input
                                            className="mono"
                                            value={condition.value}
                                            placeholder={
                                                field?.type === 'country'
                                                    ? tc('console.dk_se_1e81070d')
                                                    : field?.type === 'ip'
                                                      ? '203.0.113.0/24'
                                                      : ''
                                            }
                                            onChange={(event) =>
                                                setCondition(index, { value: event.target.value })
                                            }
                                        />
                                    )}
                                </Field>
                                <Button
                                    variant="ghost"
                                    disabled={form.data.conditions.length === 1}
                                    aria-label={consoleT('console.remove_this_condition_3b23e189')}
                                    onClick={() =>
                                        form.setData(
                                            'conditions',
                                            form.data.conditions.filter((_, at) => at !== index),
                                        )
                                    }
                                >
                                    <Icon name="trash" className="w-4 h-4" />
                                </Button>
                            </div>
                        );
                    })}
                    {form.errors.conditions !== undefined && (
                        <p role="alert" className="field-error">
                            {form.errors.conditions}
                        </p>
                    )}
                    <div>
                        <Button
                            size="sm"
                            disabled={form.data.conditions.length >= 10}
                            onClick={() =>
                                form.setData('conditions', [
                                    ...form.data.conditions,
                                    { key: rowKey(), field: 'country', operator: 'in', value: '' },
                                ])
                            }
                        >
                            {consoleRich('console._part1_add_a_condition_45d4471b', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Button>
                    </div>
                </fieldset>

                <Field label={consoleT('console.then_0597f441')} error={form.errors.action}>
                    <Select
                        value={form.data.action}
                        onValueChange={(value) => form.setData('action', value)}
                        options={actions.map((value) => ({
                            value,
                            label: ACTION_LABEL(consoleStaticText)[value],
                        }))}
                    />
                </Field>
                <Field
                    label={consoleT('console.applies_to_6687458b')}
                    error={form.errors.appliesTo}
                >
                    <Select
                        value={form.data.appliesTo}
                        onValueChange={(value) => form.setData('appliesTo', value)}
                        options={scopes.map((value) => ({
                            value,
                            label: SCOPE_LABEL(consoleStaticText)[value],
                        }))}
                    />
                </Field>
                <Checkbox
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                    label={consoleT('console.enabled_92c1cdfd')}
                    hint={consoleT('console.a_rule_that_is_off_is_kept_and_asked_nothing_dbf2f703')}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {rule === null
                            ? consoleText('console.add_rule_a27cff51')
                            : consoleText('console.save_rule_4e62c225')}
                    </Button>
                    {destroyHref !== null && rule !== null && (
                        <>
                            <Button variant="danger" onClick={() => setDeleting(true)}>
                                {consoleT('console.delete_e2d0a549')}
                            </Button>
                            <ConfirmDelete
                                open={deleting}
                                onOpenChange={setDeleting}
                                name={rule.name}
                                consequence={consoleUiText(
                                    'console.attempts_this_rule_decided_are_decided_by_the_next_matc_ebfc6722',
                                )}
                                onConfirm={() => router.delete(destroyHref)}
                            />
                        </>
                    )}
                </div>
            </form>
        </>
    );
}

RadarRuleEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
