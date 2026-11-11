import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import AccountLayout from '@/layouts/AccountLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    type AppApiKey,
    AppApiKeyList,
    Button,
    Checkbox,
    CopyButton,
    EmptyState,
    ExpiryField,
    Field,
    Icon,
    Input,
    type KeyLifetimeOption,
    PageHeader,
    Panel,
    Select,
} from '@/ui';

interface KeyAppOption {
    clientId: string;
    name: string;
    prefix: string;
    /** What THIS person holds in the app right now — the most a key of theirs can carry. */
    permissions: { name: string; description: string | null }[];
}

type Props = PageProps<{
    help: HelpContent;
    organizations: { id: string; name: string; href: string }[];
    organizationId: string | null;
    organizationName: string | null;
    apps: KeyAppOption[];
    selectedClientId: string | null;
    requestedAppUnavailable: boolean;
    returnTo: { url: string; appName: string } | null;
    keys: AppApiKey[];
    lifetimes: KeyLifetimeOption[];
    storeHref: string;
}>;

export default function ApiKeys({
    help,
    organizations,
    organizationId,
    organizationName,
    apps,
    selectedClientId,
    requestedAppUnavailable,
    returnTo,
    keys,
    lifetimes,
    storeHref,
}: Props) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    // On the flash channel: a credential in a history entry is readable by pressing Back,
    // long after the page that showed it has gone.
    const freshKey = usePage().flash.freshKey;

    return (
        <div className="space-y-6">
            {returnTo !== null && (
                /*
                    A LINK, never an automatic redirect, and only to an origin the app itself
                    registered — the server decides both.
                */
                <a
                    href={returnTo.url}
                    className="inline-flex items-center gap-2 text-sm font-medium"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    {consoleRich('console._part1_back_to_part2_3f3a5976', {
                        part1: (
                            <Icon
                                name="chevron"
                                className="w-4 h-4"
                                style={{ transform: 'rotate(90deg)' }}
                            />
                        ),
                        part2: returnTo.appName,
                    })}
                </a>
            )}

            <PageHeader
                help={help}
                description={consoleUiText(
                    'console.keys_your_own_scripts_and_integrations_use_to_call_an_a_db6b0585',
                )}
            />

            {organizations.length > 1 && organizationId !== null && (
                <div className="max-w-sm">
                    <Field
                        label={consoleUiText('console.organization_d764d425')}
                        hint={consoleUiText(
                            'console.a_key_acts_in_one_organization_switch_to_see_and_create_85f14ccb',
                        )}
                    >
                        <Select
                            value={organizationId}
                            onValueChange={(id) => {
                                const target = organizations.find((org) => org.id === id);

                                if (target !== undefined) {
                                    router.get(target.href);
                                }
                            }}
                            options={organizations.map((org) => ({
                                value: org.id,
                                label: org.name,
                            }))}
                        />
                    </Field>
                </div>
            )}

            {requestedAppUnavailable && (
                <p
                    className="rounded-xl border p-4 text-sm"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                        background: 'var(--warning-soft)',
                        color: 'var(--warning-strong)',
                    }}
                >
                    {consoleText(
                        organizationName !== null
                            ? organizations.length > 1
                                ? 'console.app_keys_unavailable_organization_switch'
                                : 'console.app_keys_unavailable_organization'
                            : organizations.length > 1
                              ? 'console.app_keys_unavailable_switch'
                              : 'console.app_keys_unavailable',
                        { organization: organizationName ?? '' },
                    )}
                </p>
            )}

            {freshKey !== undefined && <FreshKey value={freshKey} returnTo={returnTo} />}

            {organizationId === null ? (
                <Panel>
                    <EmptyState
                        icon="key"
                        title={consoleUiText('console.you_are_not_in_an_organization_yet_e107e606')}
                        description={consoleUiText(
                            'console.a_key_acts_inside_an_organization_once_you_join_one_you_3006f74d',
                        )}
                    />
                </Panel>
            ) : (
                <>
                    <Panel
                        title={consoleUiText('console.your_keys_debd32b8')}
                        description={
                            organizationName !== null
                                ? consoleFinalText('console.in_part1_1c341440', {
                                      part1: organizationName,
                                  })
                                : undefined
                        }
                        flush={keys.length > 0}
                    >
                        <AppApiKeyList
                            keys={keys}
                            empty={{
                                title: consoleText('console.no_keys_yet_3e5039d1'),
                                description: consoleText(
                                    'console.keys_you_create_appear_here_with_when_each_was_last_use_8c3ff766',
                                ),
                            }}
                            consequence={consoleUiText(
                                'console.anything_still_using_this_key_stops_working_immediately_dbb577cb',
                            )}
                        />
                    </Panel>

                    <Panel
                        title={consoleUiText('console.new_key_eda5496b')}
                        description={consoleUiText(
                            'console.pick_the_app_and_tick_only_what_the_key_needs_you_can_c_69b0060d',
                        )}
                    >
                        {apps.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title={consoleUiText(
                                    'console.no_app_here_offers_api_keys_163cd484',
                                )}
                                description={consoleUiText(
                                    'console.an_app_appears_here_once_it_lets_its_users_create_keys_577b44c0',
                                )}
                            />
                        ) : (
                            <NewKeyForm
                                key={organizationId}
                                apps={apps}
                                organizationId={organizationId}
                                selectedClientId={selectedClientId}
                                lifetimes={lifetimes}
                                storeHref={storeHref}
                            />
                        )}
                    </Panel>
                </>
            )}
        </div>
    );
}

function FreshKey({ value, returnTo }: { value: string; returnTo: Props['returnTo'] }) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    return (
        <div
            className="rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--success) 35%, transparent)',
                background: 'var(--success-soft)',
            }}
        >
            <p className="text-sm font-medium" style={{ color: 'var(--success-strong)' }}>
                {consoleUiText(
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
                    {value}
                </code>
                <CopyButton value={value} variant="primary" />
            </div>
            {returnTo !== null && (
                <p className="mt-3 text-sm">
                    {consoleRich('console.copied_it_part1_part2_54e2ca9c', {
                        part1: ' ',
                        part2: (
                            <a
                                href={returnTo.url}
                                className="font-medium"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                {consoleRich('console.back_to_part1_dc732693', {
                                    part1: returnTo.appName,
                                })}
                            </a>
                        ),
                    })}
                </p>
            )}
        </div>
    );
}

function NewKeyForm({
    apps,
    organizationId,
    selectedClientId,
    lifetimes,
    storeHref,
}: {
    apps: KeyAppOption[];
    organizationId: string;
    selectedClientId: string | null;
    lifetimes: KeyLifetimeOption[];
    storeHref: string;
}) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const form = useForm<{
        client_id: string;
        organization_id: string;
        name: string;
        permissions: string[];
        expires: string;
        expiresOn: string;
    }>({
        client_id: selectedClientId ?? '',
        organization_id: organizationId,
        name: '',
        permissions: [],
        expires: 'never',
        expiresOn: '',
    });

    const app = apps.find((candidate) => candidate.clientId === form.data.client_id);

    const toggle = (permission: string, on: boolean) =>
        form.setData(
            'permissions',
            on
                ? [...form.data.permissions, permission]
                : form.data.permissions.filter((held) => held !== permission),
        );

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(storeHref, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('name', 'permissions'),
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2 items-start">
                <Field
                    label={consoleUiText('console.app_0d04bfeb')}
                    error={form.errors.client_id ?? form.errors.organization_id}
                >
                    <Select
                        value={form.data.client_id === '' ? undefined : form.data.client_id}
                        placeholder={consoleUiText('console.choose_an_app_26c3b615')}
                        onValueChange={(clientId) => {
                            // A permission ticked for one app means nothing in another.
                            form.setData((data) => ({
                                ...data,
                                client_id: clientId,
                                permissions: [],
                            }));
                        }}
                        options={apps.map((candidate) => ({
                            value: candidate.clientId,
                            label: candidate.name,
                        }))}
                    />
                </Field>

                <Field
                    label={consoleUiText('console.name_dcd1d522')}
                    error={form.errors.name}
                    hint={consoleUiText(
                        'console.what_will_use_it_you_will_see_this_name_in_the_list_e342fdef',
                    )}
                >
                    <Input
                        name="name"
                        placeholder={consoleUiText('console.nightly_export_3d59e1bf')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            {app !== undefined && (
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {consoleUiText('console.permissions_abccc78c')}
                    </legend>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.only_what_you_can_do_in_part1_yourself_is_offered_if_yo_54637548',
                            { part1: app.name },
                        )}
                    </p>

                    {app.permissions.length === 0 ? (
                        <p className="text-sm">
                            {consoleRich(
                                'console.you_hold_no_permissions_in_part1_so_a_key_would_identif_2c680207',
                                { part1: app.name },
                            )}
                        </p>
                    ) : (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {app.permissions.map((permission) => (
                                <Checkbox
                                    key={permission.name}
                                    name="permissions[]"
                                    value={permission.name}
                                    checked={form.data.permissions.includes(permission.name)}
                                    onCheckedChange={(on) => toggle(permission.name, on)}
                                    label={<span className="mono">{permission.name}</span>}
                                    hint={permission.description ?? undefined}
                                />
                            ))}
                        </div>
                    )}

                    {form.errors.permissions !== undefined && (
                        <p role="alert" className="text-sm" style={{ color: 'var(--destructive)' }}>
                            {form.errors.permissions}
                        </p>
                    )}
                </fieldset>
            )}

            <ExpiryField
                lifetimes={lifetimes}
                lifetime={form.data.expires}
                onLifetimeChange={(expires) => form.setData('expires', expires)}
                date={form.data.expiresOn}
                onDateChange={(expiresOn) => form.setData('expiresOn', expiresOn)}
                lifetimeError={form.errors.expires}
                dateError={form.errors.expiresOn}
            />

            <Button
                type="submit"
                variant="primary"
                loading={form.processing}
                disabled={form.data.client_id === ''}
            >
                {consoleUiText('console.create_key_b6cde903')}
            </Button>
        </form>
    );
}

ApiKeys.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
