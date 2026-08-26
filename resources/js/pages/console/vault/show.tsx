import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    Field,
    Icon,
    Input,
    Kv,
    KvList,
    Panel,
    Pill,
    Textarea,
} from '@/ui';
import { statusTone } from './index';

interface Grant {
    clientId: string;
    revokeHref: string;
}

type Props = PageProps<{
    secret: {
        id: string;
        name: string;
        provider: string;
        scope: string;
        status: 'active' | 'expired' | 'revoked';
        revoked: boolean;
        rotatedAt: string | null;
        expiresAt: string | null;
    };
    grants: Grant[];
    indexHref: string;
    urls: { rotate: string; grant: string; revoke: string };
}>;

export default function VaultSecretDetail({ secret, grants, indexHref, urls }: Props) {
    const [revoking, setRevoking] = useState(false);

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
                    Token vault
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{secret.name}</h1>
                    <Badge className="mono">{secret.provider}</Badge>
                    <Pill tone={statusTone(secret.status)}>
                        {secret.status === 'revoked'
                            ? 'Revoked'
                            : secret.status === 'expired'
                              ? 'Expired'
                              : 'Active'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {secret.id}
                </p>
            </div>

            {/* The sealed value is never here — only its shape. */}
            <Panel title="Details">
                <KvList>
                    <Kv label="Provider">{secret.provider}</Kv>
                    <Kv label="Scope" prose>
                        {secret.scope}
                    </Kv>
                    <Kv label="Rotated" prose>
                        {secret.rotatedAt ?? 'never'}
                    </Kv>
                    <Kv label="Expires" prose>
                        {secret.expiresAt ?? 'never'}
                    </Kv>
                </KvList>
            </Panel>

            <Rotate revoked={secret.revoked} name={secret.name} href={urls.rotate} />

            <Grants revoked={secret.revoked} grants={grants} href={urls.grant} />

            <Panel
                title="Revoke secret"
                description={
                    secret.revoked
                        ? 'This secret is revoked — no future lease can open it.'
                        : 'Revoking is immediate and permanent. No future lease can open this secret.'
                }
            >
                {!secret.revoked && (
                    <Button size="sm" variant="danger" onClick={() => setRevoking(true)}>
                        Revoke secret
                    </Button>
                )}
            </Panel>

            <ConfirmDelete
                open={revoking}
                onOpenChange={setRevoking}
                name={secret.name}
                verb="Revoke"
                consequence="No future lease can open this secret. Revocation is immediate and permanent."
                onConfirm={() => {
                    setRevoking(false);
                    router.post(urls.revoke, {}, { preserveScroll: true });
                }}
            />
        </div>
    );
}

/**
 * Replace the sealed value.
 *
 * THE FIELD IS REVEALED, not always on screen. A password box sitting open on a page
 * somebody opened to read a rotation date is an invitation to paste a credential into a
 * form nobody meant to submit — and the warning beside it only means something once
 * somebody has decided to rotate.
 */
function Rotate({ revoked, name, href }: { revoked: boolean; name: string; href: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ secret: '' });

    return (
        <Panel
            title="Rotate"
            description={
                revoked
                    ? 'This secret is revoked — it can no longer be rotated.'
                    : 'Replace the sealed value with a new credential. The stored value is never shown.'
            }
            action={
                !revoked && !open ? (
                    <Button size="sm" className="shrink-0" onClick={() => setOpen(true)}>
                        Rotate
                    </Button>
                ) : undefined
            }
        >
            {!revoked && open && (
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, {
                            preserveScroll: true,
                            // Cleared whatever happens: the value is never echoed back, so
                            // leaving it in the field would be the one copy still in the clear.
                            onFinish: () => {
                                form.setData('secret', '');
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <Field label={`New value for ${name}`} error={form.errors.secret}>
                        <Textarea
                            name="secret"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            autoComplete="off"
                            placeholder="sk-live-…"
                            value={form.data.secret}
                            onChange={(event) => form.setData('secret', event.target.value)}
                        />
                    </Field>

                    <div
                        className="rounded-xl border p-4"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--warning) 35%, transparent)',
                            background: 'var(--warning-soft)',
                        }}
                    >
                        <p
                            className="text-sm font-medium"
                            style={{ color: 'var(--warning-strong)' }}
                        >
                            This is the only time the value is handled in the clear.
                        </p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            It replaces the sealed value on rotate and is never shown again — keep
                            your own copy if you need one.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                            Rotate
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                form.setData('secret', '');
                                setOpen(false);
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </Panel>
    );
}

/**
 * Who may lease this secret.
 *
 * DENY BY DEFAULT: nothing can open it until a client is named here, so this list is the
 * access-control decision rather than a setting. Revoked grants are not shown — a revoked
 * grant authorizes nothing, and listing it beside the live ones invites the list to be
 * read as "who has access".
 */
function Grants({
    revoked,
    grants,
    href,
}: {
    revoked: boolean;
    grants: Grant[];
    href: string;
}) {
    const [revokingClient, setRevokingClient] = useState<Grant | null>(null);
    const form = useForm({ client: '' });

    return (
        <Panel
            title="Client grants"
            description="Only the apps listed here can lease this secret. Everything else is refused."
        >
            <div className="space-y-2">
                {grants.length === 0 ? (
                    <p
                        className="rounded-xl border p-4 text-sm"
                        style={{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }}
                    >
                        No clients are authorized to lease this secret.
                    </p>
                ) : (
                    grants.map((grant) => (
                        <div
                            key={grant.clientId}
                            className="flex items-center justify-between gap-2 rounded-lg px-3 py-2"
                            style={{ background: 'var(--surface-2)' }}
                        >
                            <span className="mono text-xs break-all">{grant.clientId}</span>
                            <Button
                                size="sm"
                                variant="danger"
                                className="shrink-0"
                                onClick={() => setRevokingClient(grant)}
                            >
                                Revoke
                            </Button>
                        </div>
                    ))
                )}
            </div>

            {!revoked && (
                <form
                    className="mt-4 flex items-end gap-2 flex-wrap"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, {
                            preserveScroll: true,
                            onSuccess: () => form.reset('client'),
                        });
                    }}
                >
                    <Field
                        label="Authorize a client"
                        className="flex-1"
                        error={form.errors.client}
                    >
                        <Input
                            name="client"
                            className="mono"
                            placeholder="agent-client-1"
                            value={form.data.client}
                            onChange={(event) => form.setData('client', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                        Add grant
                    </Button>
                </form>
            )}

            <ConfirmDelete
                open={revokingClient !== null}
                onOpenChange={(open) => !open && setRevokingClient(null)}
                name={revokingClient?.clientId ?? ''}
                verb="Revoke"
                consequence="This client can no longer lease the secret. Any lease it already holds stays valid until it expires."
                onConfirm={() => {
                    const grant = revokingClient;
                    setRevokingClient(null);

                    if (grant !== null) {
                        router.delete(grant.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

VaultSecretDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
