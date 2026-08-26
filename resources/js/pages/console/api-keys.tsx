import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { relativeTime } from '@/lib/time';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    Select,
} from '@/ui';
import { destroy, store } from '@actions/App/Http/Controllers/Console/ApiKeyController';

interface ApiKey {
    id: string;
    name: string;
    role: string;
    prefix: string;
    active: boolean;
    lastUsedAt: string | null;
}

type Props = PageProps<{
    keys: ApiKey[];
    roles: { value: string; label: string }[];
}>;

export default function ApiKeys({ keys, roles }: Props) {
    // On the flash channel: a full-authority credential in a history entry is readable by
    // pressing Back, long after the page that showed it has gone.
    const freshKey = usePage().flash.freshKey;

    const [revoking, setRevoking] = useState<ApiKey | null>(null);

    const form = useForm({ name: '', role: roles[0]?.value ?? 'developer' });

    return (
        <>
            <PageHeader
                description="Machine credentials for the account management API — list environments, invite members, read billing. Each key carries a role."
                actions={
                    <Button asChild size="sm">
                        <a href="/api/v1/openapi.yaml" target="_blank" rel="noreferrer">
                            API reference
                            <Icon name="external" className="w-3.5 h-3.5" />
                        </a>
                    </Button>
                }
            />

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

            <div
                className="mt-6 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {keys.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title="No API keys yet"
                        description="Create a key to reach the account management API from your own services."
                    />
                ) : (
                    keys.map((key, index) => (
                        <div
                            key={key.id}
                            className="flex items-center gap-3 p-4"
                            style={
                                index < keys.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="font-medium truncate">{key.name}</span>
                                    <Badge>{key.role}</Badge>
                                    {!key.active && <Badge tone="danger">revoked</Badge>}
                                </div>
                                <p
                                    className="text-sm truncate mono"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {key.prefix}… ·{' '}
                                    {key.lastUsedAt === null
                                        ? 'never used'
                                        : `last used ${relativeTime(key.lastUsedAt)}`}
                                </p>
                            </div>

                            {key.active && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => setRevoking(key)}
                                >
                                    Revoke
                                </Button>
                            )}
                        </div>
                    ))
                )}
            </div>

            <div className="mt-6">
                <Panel
                    title="Create an API key"
                    description="The key inherits the role you choose and can do only what that role allows."
                >
                    <form
                        className="grid sm:grid-cols-[1fr_auto_auto] gap-2 items-start"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(store.url());
                        }}
                    >
                        <Field label={<span className="sr-only">Key name</span>} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="CI deploy"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field label={<span className="sr-only">Key role</span>} error={form.errors.role}>
                            <Select
                                value={form.data.role}
                                onValueChange={(role) => form.setData('role', role)}
                                options={roles.map((role) => ({
                                    value: role.value,
                                    label: role.label,
                                }))}
                                aria-label="Key role"
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="shrink-0"
                            loading={form.processing}
                        >
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

                    if (key !== null) {
                        router.delete(destroy.url({ key: key.id }));
                    }
                }}
            />
        </>
    );
}

ApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
