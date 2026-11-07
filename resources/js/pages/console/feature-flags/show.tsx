import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationOption, PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
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

const ON_OFF = (t: Translator['t']) => [
    { value: 'on', label: t('console.on_13001175') },
    { value: 'off', label: t('console.off_ca7981b4') },
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
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                <Breadcrumb href={indexHref} label={consoleT('console.feature_flags_20a2e59b')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title mono" style={{ overflowWrap: 'anywhere' }}>
                        {flag.key}
                    </h1>
                    <CopyButton value={flag.key} />
                    {flag.enabled ? (
                        <Badge tone="success">{consoleT('console.on_13001175')}</Badge>
                    ) : (
                        <Badge tone="warn">
                            {consoleT('console.switched_off_for_everyone_bdf42325')}
                        </Badge>
                    )}
                </div>
                <div className="mt-1">
                    <PageHeader
                        help={help}
                        description={consoleT(
                            'console.a_user_rule_beats_an_organization_rule_which_beats_the_74a29528',
                        )}
                    />
                </div>
            </div>

            <Panel title={consoleT('console.details_45989de4')}>
                <form
                    className="space-y-4"
                    style={{ maxWidth: '36rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        details.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <Field
                        label={consoleT('console.description_526e0087')}
                        optional
                        error={details.errors.description}
                    >
                        <Input
                            name="description"
                            value={details.data.description}
                            onChange={(event) => details.setData('description', event.target.value)}
                        />
                    </Field>
                    <Checkbox
                        checked={details.data.enabled}
                        onCheckedChange={(checked) => details.setData('enabled', checked)}
                        label={consoleT('console.switched_on_59291b6d')}
                        hint={consoleT(
                            'console.the_kill_switch_off_the_flag_is_off_for_everyone_whatev_90bda36f',
                        )}
                    />
                    <Checkbox
                        checked={details.data.defaultValue}
                        onCheckedChange={(checked) => details.setData('defaultValue', checked)}
                        label={consoleT('console.on_by_default_e1589716')}
                        hint={consoleT('console.the_answer_for_everyone_no_rule_names_6266235c')}
                    />
                    <Button type="submit" size="sm" variant="primary" loading={details.processing}>
                        {consoleT('console.save_changes_dd0ae7a5')}
                    </Button>
                </form>
            </Panel>

            <Panel
                title={consoleT('console.who_it_is_on_for_3ac233e0')}
                description={consoleT(
                    'console.rules_for_named_organizations_and_users_and_a_rollout_o_fa570c8c',
                )}
            >
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        targeting.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <RuleList
                        title={consoleT('console.organizations_2730183d')}
                        empty={consoleUiText('console.no_organization_rules_f1f852f5')}
                        rules={targeting.data.organizations}
                        error={targeting.errors.organizations}
                        onChange={(id, enabled) => setRule('organizations', id, enabled)}
                        onRemove={(id) => removeRule('organizations', id)}
                    >
                        <Field label={consoleT('console.add_an_organization_c220e8cd')}>
                            <OrganizationField
                                lookupHref={lookupHref}
                                value={null}
                                placeholder={consoleUiText('console.find_an_organization_d9649cbf')}
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
                        title={consoleT('console.users_6b0cc904')}
                        empty={consoleUiText('console.no_user_rules_800f3bd9')}
                        rules={targeting.data.users}
                        error={targeting.errors.users}
                        onChange={(id, enabled) => setRule('users', id, enabled)}
                        onRemove={(id) => removeRule('users', id)}
                    >
                        <div className="flex gap-2 items-end">
                            <Field
                                label={consoleT('console.add_a_user_ac856d22')}
                                hint={consoleT(
                                    'console.their_email_address_or_their_user_id_ee04b171',
                                )}
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
                                {consoleT('console.add_9fd728c6')}
                            </Button>
                        </div>
                    </RuleList>

                    <Field
                        label={consoleT('console.rollout_b14395be')}
                        optional
                        hint={consoleT(
                            'console.on_for_this_percentage_of_everyone_the_rules_above_do_n_6ca1e886',
                        )}
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
                        {consoleT('console.save_rules_e5dbdffb')}
                    </Button>
                </form>
            </Panel>

            <Panel
                title={consoleT('console.is_it_on_for_29bc0994')}
                description={consoleT(
                    'console.the_answer_the_token_and_the_api_give_and_the_rule_that_24f41cf9',
                )}
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
                    <Field
                        label={consoleT('console.user_b512d97e')}
                        optional
                        hint={consoleT('console.an_email_address_or_a_user_id_0f810f2c')}
                    >
                        <Input
                            name="user"
                            spellCheck={false}
                            autoCapitalize="off"
                            value={asUser}
                            onChange={(event) => setAsUser(event.target.value)}
                        />
                    </Field>
                    <Field label={consoleT('console.organization_d764d425')} optional>
                        <OrganizationField
                            lookupHref={lookupHref}
                            value={asOrganization}
                            onChange={setAsOrganization}
                        />
                    </Field>
                    <Button type="submit" size="sm">
                        {consoleT('console.check_9d60841e')}
                    </Button>
                </form>

                {evaluation !== null && (
                    <output
                        className="mt-4 rounded-lg p-3 flex items-center gap-3 flex-wrap"
                        style={{ border: '1px solid var(--border)' }}
                    >
                        {evaluation.enabled ? (
                            <Badge tone="success">{consoleT('console.on_13001175')}</Badge>
                        ) : (
                            <Badge>{consoleT('console.off_ca7981b4')}</Badge>
                        )}
                        <span className="text-sm">{evaluation.explanation}</span>
                        <code className="mono text-xs" style={{ color: 'var(--faint)' }}>
                            {evaluation.reason}
                        </code>
                    </output>
                )}
            </Panel>

            <Panel
                title={consoleT('console.delete_flag_22cb12e1')}
                description={consoleT(
                    'console.its_rules_go_with_it_apps_asking_for_its_key_get_off_fr_aacd29e9',
                )}
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    {consoleT('console.delete_flag_22cb12e1')}
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={flag.key}
                consequence={consoleUiText(
                    'console.every_app_asking_for_this_flag_gets_off_this_cannot_be_e3ed99c1',
                )}
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                                aria-label={consoleFinalText(
                                    'console.on_or_off_for_part1_27c97556',
                                    { part1: rule.label },
                                )}
                                value={rule.enabled ? 'on' : 'off'}
                                onValueChange={(value) => onChange(rule.id, value === 'on')}
                                options={ON_OFF(consoleOptionText)}
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => onRemove(rule.id)}
                            >
                                {consoleT('console.remove_c3812fc4')}
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
