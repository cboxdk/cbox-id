import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
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

const ACTION_LABEL: Record<Verdict, string> = {
    allow: 'Allow — skip every rule after this one',
    challenge: 'Challenge — a second factor on sign-in, a CAPTCHA or emailed code on sign-up',
    block: 'Block — refuse with a generic message',
};
const SCOPE_LABEL: Record<Scope, string> = {
    all: 'Sign-in and sign-up',
    sign_in: 'Sign-in only',
    sign_up: 'Sign-up only',
};

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
            <PageHeader
                help={help}
                description="When EVERY condition holds, Radar takes this rule's action and asks no rule after it. Rules are asked after the allow and deny lists and before the built-in rules."
                actions={
                    <Button asChild>
                        <Link href={rulesHref}>
                            <Icon name="arrow-left" className="w-4 h-4" />
                            Rules
                        </Link>
                    </Button>
                }
            />

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
                <Field label="Name" error={form.errors.name}>
                    <Input
                        value={form.data.name}
                        maxLength={120}
                        placeholder="Challenge sign-ins from outside the Nordics"
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
                <Field
                    label="Why it exists"
                    error={form.errors.description}
                    hint="Optional — for whoever reads it next."
                >
                    <Textarea
                        value={form.data.description}
                        maxLength={500}
                        rows={2}
                        onChange={(event) => form.setData('description', event.target.value)}
                    />
                </Field>

                <fieldset className="grid gap-3">
                    <legend className="text-sm font-semibold">When</legend>
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
                                <Field label={index === 0 ? 'Fact' : 'and'}>
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
                                <Field label="Is">
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
                                <Field label={operator?.list ? 'Values, comma-separated' : 'Value'}>
                                    {field?.type === 'boolean' ? (
                                        <Select
                                            value={
                                                condition.value === '' ? undefined : condition.value
                                            }
                                            onValueChange={(value) =>
                                                setCondition(index, { value })
                                            }
                                            options={[
                                                { value: 'true', label: 'yes' },
                                                { value: 'false', label: 'no' },
                                            ]}
                                        />
                                    ) : (
                                        <Input
                                            className="mono"
                                            value={condition.value}
                                            placeholder={
                                                field?.type === 'country'
                                                    ? 'DK, SE'
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
                                    aria-label="Remove this condition"
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
                            <Icon name="plus" className="w-4 h-4" />
                            Add a condition
                        </Button>
                    </div>
                </fieldset>

                <Field label="Then" error={form.errors.action}>
                    <Select
                        value={form.data.action}
                        onValueChange={(value) => form.setData('action', value)}
                        options={actions.map((value) => ({ value, label: ACTION_LABEL[value] }))}
                    />
                </Field>
                <Field label="On" error={form.errors.appliesTo}>
                    <Select
                        value={form.data.appliesTo}
                        onValueChange={(value) => form.setData('appliesTo', value)}
                        options={scopes.map((value) => ({ value, label: SCOPE_LABEL[value] }))}
                    />
                </Field>
                <Checkbox
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                    label="Enabled"
                    hint="A rule that is off is kept, and asked nothing."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {rule === null ? 'Add rule' : 'Save rule'}
                    </Button>
                    {destroyHref !== null && rule !== null && (
                        <>
                            <Button variant="danger" onClick={() => setDeleting(true)}>
                                Delete
                            </Button>
                            <ConfirmDelete
                                open={deleting}
                                onOpenChange={setDeleting}
                                name={rule.name}
                                consequence="Attempts this rule decided are decided by the next matching rule, or the built-in rules, from the next attempt."
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
