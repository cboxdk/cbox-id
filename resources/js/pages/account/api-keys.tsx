import { useTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
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
    const { t: tc } = useTranslator();

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
                    <Icon
                        name="chevron"
                        className="w-4 h-4"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    {tc('console.back_to_1b52b139')}
                    {returnTo.appName}
                </a>
            )}

            <PageHeader
                help={help}
                description={tc('console.keys_your_own_scripts_and_integrations_use_t_db6b0585')}
            />

            {organizations.length > 1 && organizationId !== null && (
                <div className="max-w-sm">
                    <Field
                        label={tc('console.organization_d764d425')}
                        hint={tc('console.a_key_acts_in_one_organization_switch_to_see_85f14ccb')}
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
                    {tc('console.the_app_that_sent_you_here_does_not_offer_ap_ffa3d94b')}
                    {organizationName !== null
                        ? tc('console.in_value0_09a02660', { value0: organizationName })
                        : ''}
                    .
                    {organizations.length > 1
                        ? tc('console.try_another_organization_or_ask_cf2cbb78')
                        : tc('console.ask_4e90bdc3')}
                    {tc('console.the_app_s_support_team_f0dda4a2')}
                </p>
            )}

            {freshKey !== undefined && <FreshKey value={freshKey} returnTo={returnTo} />}

            {organizationId === null ? (
                <Panel>
                    <EmptyState
                        icon="key"
                        title={tc('console.you_are_not_in_an_organization_yet_e107e606')}
                        description={tc(
                            'console.a_key_acts_inside_an_organization_once_you_j_3006f74d',
                        )}
                    />
                </Panel>
            ) : (
                <>
                    <Panel
                        title={tc('console.your_keys_debd32b8')}
                        description={
                            organizationName !== null
                                ? tc('console.in_value0_75208618', { value0: organizationName })
                                : undefined
                        }
                        flush={keys.length > 0}
                    >
                        <AppApiKeyList
                            keys={keys}
                            empty={{
                                title: tc('console.no_keys_yet_3e5039d1'),
                                description: tc(
                                    'console.keys_you_create_appear_here_with_when_each_w_8c3ff766',
                                ),
                            }}
                            consequence={tc(
                                'console.anything_still_using_this_key_stops_working__dbb577cb',
                            )}
                        />
                    </Panel>

                    <Panel
                        title={tc('console.new_key_eda5496b')}
                        description={tc(
                            'console.pick_the_app_and_tick_only_what_the_key_need_69b0060d',
                        )}
                    >
                        {apps.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title={tc('console.no_app_here_offers_api_keys_163cd484')}
                                description={tc(
                                    'console.an_app_appears_here_once_it_lets_its_users_c_577b44c0',
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
    const { t: tc } = useTranslator();

    return (
        <div
            className="rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--success) 35%, transparent)',
                background: 'var(--success-soft)',
            }}
        >
            <p className="text-sm font-medium" style={{ color: 'var(--success-strong)' }}>
                {tc('console.copy_your_key_now_you_won_t_be_able_to_see_i_36d9f33f')}
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
                    {tc('console.copied_it_b4914b22')}{' '}
                    <a
                        href={returnTo.url}
                        className="font-medium"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {tc('console.back_to_1b52b139')}
                        {returnTo.appName}
                    </a>
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
    const { t: tc } = useTranslator();

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
                    label={tc('console.app_0d04bfeb')}
                    error={form.errors.client_id ?? form.errors.organization_id}
                >
                    <Select
                        value={form.data.client_id === '' ? undefined : form.data.client_id}
                        placeholder={tc('console.choose_an_app_26c3b615')}
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
                    label={tc('console.name_dcd1d522')}
                    error={form.errors.name}
                    hint={tc('console.what_will_use_it_you_will_see_this_name_in_t_e342fdef')}
                >
                    <Input
                        name="name"
                        placeholder={tc('console.nightly_export_3d59e1bf')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            {app !== undefined && (
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {tc('console.permissions_abccc78c')}
                    </legend>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.only_what_you_can_do_in_b15893ae')}
                        {app.name}
                        {tc('console.yourself_is_offered_if_you_lose_a_permission_502e6987')}
                    </p>

                    {app.permissions.length === 0 ? (
                        <p className="text-sm">
                            {tc('console.you_hold_no_permissions_in_be94ed13')}
                            {app.name}
                            {tc('console.so_a_key_would_identify_you_to_it_and_do_not_455f0364')}
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
                {tc('console.create_key_b6cde903')}
            </Button>
        </form>
    );
}

ApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
