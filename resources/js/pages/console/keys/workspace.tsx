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
import { store } from '@actions/App/Http/Controllers/Console/ApiKeyController';

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

export default function WorkspaceKeys({ tabs, keys, roles, lifetimes, help }: Props) {
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
                description="The workspace's own keys, for the workspace API — list projects and environments, create environments, list and invite your team. Each key carries a built-in role."
                actions={
                    <Button asChild size="sm">
                        <a href="/api/v1/openapi.yaml" target="_blank" rel="noreferrer">
                            API reference
                            <Icon name="external" className="w-3.5 h-3.5" />
                        </a>
                    </Button>
                }
            />

            <div className="mt-4">
                <LinkTabs tabs={tabs} label="Key types" />
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
                            {freshKey}
                        </code>
                        <CopyButton value={freshKey} variant="primary" />
                    </div>
                </div>
            )}

            <div className="mt-6 space-y-6">
                <Panel title="Keys" flush={keys.length > 0}>
                    {keys.length === 0 ? (
                        <EmptyState
                            icon="key"
                            title="No workspace keys yet"
                            description="Create a key to reach the workspace API from your own services."
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
                                        Revoke
                                    </Button>
                                )}
                            </div>
                        ))
                    )}
                </Panel>

                <Panel
                    title="New key"
                    description="The key holds the built-in role you choose and can do only what that role allows."
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
                            <Field label="Name" error={form.errors.name}>
                                <Input
                                    name="name"
                                    placeholder="CI deploy"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            <Field label="Built-in role" error={form.errors.role}>
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
                            Create key
                        </Button>
                    </form>
                </Panel>
            </div>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => setRevoking(open ? revoking : null)}
                name={revoking?.name ?? ''}
                verb="Revoke"
                consequence="Any integration still presenting this key stops working immediately. This cannot be undone."
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

WorkspaceKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
