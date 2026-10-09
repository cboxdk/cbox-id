import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationOption, PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    OrganizationField,
    PageHeader,
    Panel,
    Select,
} from '@/ui';

/** One user or organization rule: who, and whether the flag is on or off for them. */
interface Rule {
    id: string;
    label: string;
    enabled: boolean;
}

interface Evaluation {
    user: string;
    organization: string;
    enabled: boolean;
    reason: string;
    explanation: string;
}

type Props = PageProps<{
    help: HelpContent;
    flag: {
        id: string;
        key: string;
        description: string;
        enabled: boolean;
        defaultValue: boolean;
        rolloutPercentage: number | null;
    };
    users: Rule[];
    organizations: Rule[];
    evaluation: Evaluation | null;
    lookupHref: string;
    indexHref: string;
    urls: { show: string; update: string; destroy: string };
}>;

const ON_OFF = [
    { value: 'on', label: 'On' },
    { value: 'off', label: 'Off' },
];

export default function FeatureFlagDetail({
    help,
    flag,
    users,
    organizations,
    evaluation,
    lookupHref,
    indexHref,
    urls,
}: Props) {
    const [deleting, setDeleting] = useState(false);

    const details = useForm({
        description: flag.description,
        enabled: flag.enabled,
        defaultValue: flag.defaultValue,
    });

    const targeting = useForm({
        organizations: organizations,
        users: users,
        rolloutPercentage: flag.rolloutPercentage === null ? '' : String(flag.rolloutPercentage),
    });

    // A refusal about no single field of this form (`form`, from RunsActions::act()).
    const formError = (targeting.errors as Record<string, string | undefined>).form;

    const [newUser, setNewUser] = useState('');
    const [asUser, setAsUser] = useState(evaluation?.user ?? '');
    const [asOrganization, setAsOrganization] = useState<OrganizationOption | null>(
        evaluation === null || evaluation.organization === ''
            ? null
            : {
                  id: evaluation.organization,
                  name:
                      organizations.find((rule) => rule.id === evaluation.organization)?.label ??
                      evaluation.organization,
              },
    );

    const setRule = (kind: 'organizations' | 'users', id: string, enabled: boolean) =>
        targeting.setData(
            kind,
            targeting.data[kind].map((rule) => (rule.id === id ? { ...rule, enabled } : rule)),
        );

    const removeRule = (kind: 'organizations' | 'users', id: string) =>
        targeting.setData(
            kind,
            targeting.data[kind].filter((rule) => rule.id !== id),
        );

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Feature flags
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title mono" style={{ overflowWrap: 'anywhere' }}>
                        {flag.key}
                    </h1>
                    <CopyButton value={flag.key} />
                    {flag.enabled ? (
                        <Badge tone="success">On</Badge>
                    ) : (
                        <Badge tone="warn">Switched off for everyone</Badge>
                    )}
                </div>
                <div className="mt-1">
                    <PageHeader
                        help={help}
                        description="A user rule beats an organization rule, which beats the rollout, which beats the default. Switched off, the flag is off for everyone."
                    />
                </div>
            </div>

            <Panel title="Details">
                <form
                    className="space-y-4"
                    style={{ maxWidth: '36rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        details.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <Field label="Description" optional error={details.errors.description}>
                        <Input
                            name="description"
                            value={details.data.description}
                            onChange={(event) => details.setData('description', event.target.value)}
                        />
                    </Field>
                    <Checkbox
                        checked={details.data.enabled}
                        onCheckedChange={(checked) => details.setData('enabled', checked)}
                        label="Switched on"
                        hint="The kill switch. Off, the flag is off for everyone, whatever the rules below say."
                    />
                    <Checkbox
                        checked={details.data.defaultValue}
                        onCheckedChange={(checked) => details.setData('defaultValue', checked)}
                        label="On by default"
                        hint="The answer for everyone no rule names."
                    />
                    <Button type="submit" size="sm" variant="primary" loading={details.processing}>
                        Save changes
                    </Button>
                </form>
            </Panel>

            <Panel
                title="Who it is on for"
                description="Rules for named organizations and users, and a rollout over everyone else. Saved together."
            >
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        targeting.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <RuleList
                        title="Organizations"
                        empty="No organization rules."
                        rules={targeting.data.organizations}
                        error={targeting.errors.organizations}
                        onChange={(id, enabled) => setRule('organizations', id, enabled)}
                        onRemove={(id) => removeRule('organizations', id)}
                    >
                        <Field label="Add an organization">
                            <OrganizationField
                                lookupHref={lookupHref}
                                value={null}
                                placeholder="Find an organization"
                                onChange={(chosen) => {
                                    if (
                                        chosen !== null &&
                                        !targeting.data.organizations.some(
                                            (rule) => rule.id === chosen.id,
                                        )
                                    ) {
                                        targeting.setData('organizations', [
                                            ...targeting.data.organizations,
                                            { id: chosen.id, label: chosen.name, enabled: true },
                                        ]);
                                    }
                                }}
                            />
                        </Field>
                    </RuleList>

                    <RuleList
                        title="Users"
                        empty="No user rules."
                        rules={targeting.data.users}
                        error={targeting.errors.users}
                        onChange={(id, enabled) => setRule('users', id, enabled)}
                        onRemove={(id) => removeRule('users', id)}
                    >
                        <div className="flex gap-2 items-end">
                            <Field
                                label="Add a user"
                                hint="Their email address, or their user id."
                                className="flex-1"
                            >
                                <Input
                                    name="newUser"
                                    spellCheck={false}
                                    autoCapitalize="off"
                                    placeholder="ada@example.com"
                                    value={newUser}
                                    onChange={(event) => setNewUser(event.target.value)}
                                />
                            </Field>
                            <Button
                                type="button"
                                size="sm"
                                disabled={newUser.trim() === ''}
                                onClick={() => {
                                    const id = newUser.trim();

                                    if (!targeting.data.users.some((rule) => rule.id === id)) {
                                        targeting.setData('users', [
                                            ...targeting.data.users,
                                            { id, label: id, enabled: true },
                                        ]);
                                    }

                                    setNewUser('');
                                }}
                            >
                                Add
                            </Button>
                        </div>
                    </RuleList>

                    <Field
                        label="Rollout"
                        optional
                        hint="On for this percentage of everyone the rules above do not name, by a stable hash of their user id — the same people every time, and raising it only adds people. Leave it empty for no rollout."
                        error={targeting.errors.rolloutPercentage}
                    >
                        <Input
                            name="rolloutPercentage"
                            type="number"
                            min={0}
                            max={100}
                            placeholder="25"
                            style={{ maxWidth: '8rem' }}
                            value={targeting.data.rolloutPercentage}
                            onChange={(event) =>
                                targeting.setData('rolloutPercentage', event.target.value)
                            }
                        />
                    </Field>

                    {formError !== undefined && (
                        <p className="field-error" role="alert">
                            {formError}
                        </p>
                    )}

                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        loading={targeting.processing}
                    >
                        Save rules
                    </Button>
                </form>
            </Panel>

            <Panel
                title="Is it on for…?"
                description="The answer the token and the API give, and the rule that decided."
            >
                <form
                    className="space-y-4"
                    style={{ maxWidth: '36rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            urls.show,
                            {
                                user: asUser.trim(),
                                organization: asOrganization?.id ?? '',
                            },
                            { preserveScroll: true, preserveState: true, replace: true },
                        );
                    }}
                >
                    <Field label="User" optional hint="An email address or a user id.">
                        <Input
                            name="user"
                            spellCheck={false}
                            autoCapitalize="off"
                            value={asUser}
                            onChange={(event) => setAsUser(event.target.value)}
                        />
                    </Field>
                    <Field label="Organization" optional>
                        <OrganizationField
                            lookupHref={lookupHref}
                            value={asOrganization}
                            onChange={setAsOrganization}
                        />
                    </Field>
                    <Button type="submit" size="sm">
                        Check
                    </Button>
                </form>

                {evaluation !== null && (
                    <output
                        className="mt-4 rounded-lg p-3 flex items-center gap-3 flex-wrap"
                        style={{ border: '1px solid var(--border)' }}
                    >
                        {evaluation.enabled ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>}
                        <span className="text-sm">{evaluation.explanation}</span>
                        <code className="mono text-xs" style={{ color: 'var(--faint)' }}>
                            {evaluation.reason}
                        </code>
                    </output>
                )}
            </Panel>

            <Panel
                title="Delete flag"
                description="Its rules go with it. Apps asking for its key get off from now on; tokens already issued keep naming it until they expire."
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    Delete flag
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={flag.key}
                consequence="Every app asking for this flag gets off. This cannot be undone."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/** The rules of one kind, each switchable on or off and removable, and how to add one. */
function RuleList({
    title,
    empty,
    rules,
    error,
    onChange,
    onRemove,
    children,
}: {
    title: string;
    empty: string;
    rules: Rule[];
    error: string | undefined;
    onChange: (id: string, enabled: boolean) => void;
    onRemove: (id: string) => void;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-3">
            <p className="text-sm font-medium">{title}</p>
            {rules.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {empty}
                </p>
            ) : (
                <ul className="space-y-2">
                    {rules.map((rule) => (
                        <li
                            key={rule.id}
                            className="rounded-lg p-2 pl-3 flex items-center gap-3"
                            style={{ border: '1px solid var(--border)' }}
                        >
                            <span className="min-w-0 flex-1 truncate text-sm" title={rule.id}>
                                {rule.label}
                            </span>
                            <Select
                                name={`rule-${rule.id}`}
                                aria-label={`On or off for ${rule.label}`}
                                value={rule.enabled ? 'on' : 'off'}
                                onValueChange={(value) => onChange(rule.id, value === 'on')}
                                options={ON_OFF}
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => onRemove(rule.id)}
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {error !== undefined && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
            {children}
        </div>
    );
}

FeatureFlagDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
