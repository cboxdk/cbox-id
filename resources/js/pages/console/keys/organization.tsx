import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    ExpiryField,
    Field,
    Icon,
    Input,
    type KeyLifecycle,
    type KeyLifetimeOption,
    KeyStatusPill,
    KeyTimeline,
    type LinkTab,
    LinkTabs,
    PageHeader,
    Panel,
    Select,
} from '@/ui';
import { store } from '@routes/orgs/settings/api-keys/organization';

interface ApiKey {
    id: string;
    name: string;
    role: string;
    prefix: string;
    lifecycle: KeyLifecycle;
    /** Null once the key is no longer active: there is nothing left to stop. */
    revokeHref: string | null;
}

type Props = PageProps<{
    tabs: LinkTab[];
    keys: ApiKey[];
    roles: { value: string; label: string }[];
    lifetimes: KeyLifetimeOption[];
    help: HelpContent;
}>;

export default function OrganizationKeys({ tabs, keys, roles, lifetimes, help }: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // On the flash channel: a full-authority credential in a history entry is readable by
    // pressing Back, long after the page that showed it has gone.
    const freshKey = usePage().flash.freshKey;

    const [revoking, setRevoking] = useState<ApiKey | null>(null);

    const form = useForm({
        name: '',
        role: roles[0]?.value ?? 'developer',
        expires: 'never',
        expiresOn: '',
    });

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.the_organization_s_own_keys_for_the_organization_api_li_86017b30',
                )}
                actions={
                    <Button asChild size="sm">
                        <a href="/api/v1/workspace/openapi.yaml" target="_blank" rel="noreferrer">
                            {consoleRich('console.api_reference_part1_8da5aee1', {
                                part1: <Icon name="external" className="w-3.5 h-3.5" />,
                            })}
                        </a>
                    </Button>
                }
            />

            <div className="mt-4">
                <LinkTabs tabs={tabs} label={consoleT('console.key_types_0562e0e6')} />
            </div>

            {freshKey !== undefined && (
                <div
                    className="mt-6 rounded-xl border p-4"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--success) 35%, transparent)',
                        background: 'var(--success-soft)',
                    }}
                >
                    <p className="text-sm font-medium" style={{ color: 'var(--success-strong)' }}>
                        {consoleT(
                            'console.copy_your_key_now_you_won_t_be_able_to_see_it_again_36d9f33f',
                        )}
                    </p>
                    <div className="mt-3 flex items-center gap-2">
                        <code
                            className="flex-1 min-w-0 truncate rounded-lg px-3 py-2 text-sm"
                            style={{
                                background: 'var(--background)',
                                border: '1px solid var(--border)',
                            }}
                        >
                            {freshKey}
                        </code>
                        <CopyButton value={freshKey} variant="primary" />
                    </div>
                </div>
            )}

            <div className="mt-6 space-y-6">
                <Panel title={consoleT('console.keys_f0d66a79')} flush={keys.length > 0}>
                    {keys.length === 0 ? (
                        <EmptyState
                            icon="key"
                            equivalent="keys.organization.create"
                            title={consoleT('console.no_organization_keys_yet_7338155c')}
                            description={consoleT(
                                'console.create_a_key_to_reach_the_organization_api_from_your_ow_55fda87d',
                            )}
                        />
                    ) : (
                        keys.map((key, index) => (
                            <div
                                key={key.id}
                                className="flex items-start gap-3 flex-wrap px-4 py-3"
                                style={
                                    index < keys.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-medium truncate">{key.name}</span>
                                        <KeyStatusPill lifecycle={key.lifecycle} />
                                        <Badge>{key.role}</Badge>
                                    </div>
                                    <KeyTimeline lifecycle={key.lifecycle} prefix={key.prefix} />
                                </div>

                                {key.revokeHref !== null && (
                                    <Button
                                        size="sm"
                                        variant="danger"
                                        className="shrink-0"
                                        onClick={() => setRevoking(key)}
                                    >
                                        {consoleT('console.revoke_87e6d00b')}
                                    </Button>
                                )}
                            </div>
                        ))
                    )}
                </Panel>

                <Panel
                    title={consoleT('console.new_key_eda5496b')}
                    description={consoleT(
                        'console.the_key_holds_the_built_in_role_you_choose_and_can_do_o_b7c48a4b',
                    )}
                >
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(store.url(), {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name'),
                            });
                        }}
                    >
                        <div className="grid gap-3 sm:grid-cols-2 items-start">
                            <Field
                                label={consoleT('console.name_dcd1d522')}
                                error={form.errors.name}
                            >
                                <Input
                                    name="name"
                                    placeholder={consoleUiText('console.ci_deploy_91ef66b5')}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            <Field
                                label={consoleT('console.built_in_role_9f680a16')}
                                error={form.errors.role}
                            >
                                <Select
                                    value={form.data.role}
                                    onValueChange={(role) => form.setData('role', role)}
                                    options={roles.map((role) => ({
                                        value: role.value,
                                        label: role.label,
                                    }))}
                                />
                            </Field>
                        </div>

                        <ExpiryField
                            lifetimes={lifetimes}
                            lifetime={form.data.expires}
                            onLifetimeChange={(expires) => form.setData('expires', expires)}
                            date={form.data.expiresOn}
                            onDateChange={(expiresOn) => form.setData('expiresOn', expiresOn)}
                            lifetimeError={form.errors.expires}
                            dateError={form.errors.expiresOn}
                        />

                        <Button type="submit" variant="primary" loading={form.processing}>
                            {consoleT('console.create_key_b6cde903')}
                        </Button>
                    </form>
                </Panel>
            </div>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => setRevoking(open ? revoking : null)}
                name={revoking?.name ?? ''}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.any_integration_still_presenting_this_key_stops_working_2b1f7061',
                )}
                onConfirm={() => {
                    const key = revoking;
                    setRevoking(null);

                    if (key?.revokeHref) {
                        router.delete(key.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

OrganizationKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
