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
                    Back to {returnTo.appName}
                </a>
            )}

            <PageHeader
                help={help}
                description="Keys your own scripts and integrations use to call an app's API as you. A key can only do what you can do in that app."
            />

            {organizations.length > 1 && organizationId !== null && (
                <div className="max-w-sm">
                    <Field
                        label="Organization"
                        hint="A key acts in one organization. Switch to see and create keys in another."
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
                    The app that sent you here does not offer API keys
                    {organizationName !== null ? ` in ${organizationName}` : ''}.
                    {organizations.length > 1 ? ' Try another organization, or ask' : ' Ask'} the
                    app's support team.
                </p>
            )}

            {freshKey !== undefined && <FreshKey value={freshKey} returnTo={returnTo} />}

            {organizationId === null ? (
                <Panel>
                    <EmptyState
                        icon="key"
                        title="You are not in an organization yet"
                        description="A key acts inside an organization. Once you join one, you can create keys for its apps here."
                    />
                </Panel>
            ) : (
                <>
                    <Panel
                        title="Your keys"
                        description={
                            organizationName !== null ? `In ${organizationName}.` : undefined
                        }
                        flush={keys.length > 0}
                    >
                        <AppApiKeyList
                            keys={keys}
                            empty={{
                                title: 'No keys yet',
                                description:
                                    'Keys you create appear here, with when each was last used.',
                            }}
                            consequence="Anything still using this key stops working immediately. This cannot be undone."
                        />
                    </Panel>

                    <Panel
                        title="New key"
                        description="Pick the app and tick only what the key needs. You can create as many keys as you like, one per integration."
                    >
                        {apps.length === 0 ? (
                            <EmptyState
                                icon="key"
                                title="No app here offers API keys"
                                description="An app appears here once it lets its users create keys for its API."
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
    return (
        <div
            className="rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--success) 35%, transparent)',
                background: 'var(--success-soft)',
            }}
        >
            <p className="text-sm font-medium" style={{ color: 'var(--success-strong)' }}>
                Copy your key now — you won't be able to see it again.
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
                    Copied it?{' '}
                    <a
                        href={returnTo.url}
                        className="font-medium"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        Back to {returnTo.appName}
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
                <Field label="App" error={form.errors.client_id ?? form.errors.organization_id}>
                    <Select
                        value={form.data.client_id === '' ? undefined : form.data.client_id}
                        placeholder="Choose an app…"
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
                    label="Name"
                    error={form.errors.name}
                    hint="What will use it — you will see this name in the list."
                >
                    <Input
                        name="name"
                        placeholder="Nightly export"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            {app !== undefined && (
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Permissions</legend>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Only what you can do in {app.name} yourself is offered. If you lose a
                        permission later, the key loses it too.
                    </p>

                    {app.permissions.length === 0 ? (
                        <p className="text-sm">
                            You hold no permissions in {app.name}, so a key would identify you to it
                            and do nothing else.
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
                Create key
            </Button>
        </form>
    );
}

ApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
