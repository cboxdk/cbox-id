import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
} from '@/ui';

interface Group {
    id: string;
    name: string;
    roleIds: string[];
}

interface RoleOption {
    id: string;
    name: string;
    /** The app that declares it, when it is not the organization's own. */
    app: string | null;
}

type Props = PageProps<{
    directory: {
        id: string;
        name: string;
        provider: string;
        providerLabel: string;
        scim: boolean;
        active: boolean;
        status: string;
        lastSyncError: string | null;
    };
    setup: { steps: string[]; docs: string; credentials: unknown[] } | null;
    organizationName: string;
    scimBaseUrl: string;
    groups: Group[];
    roles: RoleOption[];
    mayChange: boolean;
    indexHref: string;
    urls: {
        update: string;
        rotate: string;
        toggle: string;
        destroy: string;
        map: string;
    };
}>;

export default function DirectoryDetail({
    directory,
    setup,
    organizationName,
    scimBaseUrl,
    groups,
    roles,
    mayChange,
    indexHref,
    urls,
}: Props) {
    // The bearer token, on the flash channel and nowhere else: it authenticates every
    // inbound provisioning call, and props are written into the history entry.
    const newToken = usePage().flash.newToken;

    const [confirming, setConfirming] = useState<'rotate' | 'delete' | null>(null);

    const nameForm = useForm({ name: directory.name });

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
                    Sync users in
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{directory.name}</h1>
                    <Badge>{directory.providerLabel}</Badge>
                    <Pill tone={directory.active ? 'success' : 'warning'}>
                        {directory.active ? 'Active' : directory.status}
                    </Pill>
                </div>
                <p className="mt-1 text-sm" style={{ color: 'var(--faint)' }}>
                    {organizationName}
                </p>
            </div>

            {newToken !== undefined && <RevealedToken token={newToken} endpoint={scimBaseUrl} />}

            {/*
                A sync that has started failing is the one moment somebody needs the
                provider's own guide after setup: "Graph users request failed (403)" is a
                permission that was never granted or a secret that expired, and the steps
                that say which are the ones the create page showed.
            */}
            {directory.lastSyncError !== null && (
                <Panel
                    title="Last sync failed"
                    action={
                        setup !== null ? (
                            <Button asChild size="sm" className="shrink-0">
                                <a href={setup.docs} target="_blank" rel="noreferrer">
                                    Provider guide
                                    <Icon name="external" className="w-3.5 h-3.5" />
                                </a>
                            </Button>
                        ) : undefined
                    }
                >
                    <p className="text-sm mono" style={{ color: 'var(--destructive)' }}>
                        {directory.lastSyncError}
                    </p>
                </Panel>
            )}

            {directory.scim && (
                <Panel
                    title="SCIM endpoint"
                    description="What your identity provider posts to. The bearer token is shown once, when it is minted."
                >
                    <div className="flex items-center gap-2">
                        <code className="flex-1 min-w-0 truncate mono text-sm">{scimBaseUrl}</code>
                        <CopyButton value={scimBaseUrl} />
                    </div>
                </Panel>
            )}

            {/*
                Group → role. This is what makes a directory worth connecting: access
                follows the group somebody is already in, so joining a team grants what the
                team has and leaving it takes it away.
            */}
            <Panel
                title="Groups"
                description="Map a group onto the roles everyone in it should hold. Membership syncs, so the roles follow."
            >
                {groups.length === 0 ? (
                    <EmptyState
                        icon="directory"
                        title="No groups synced yet"
                        description="Groups appear here after the first sync that includes them. If your provider filters which groups it sends, this list is that filter."
                    />
                ) : (
                    <div className="space-y-4">
                        {groups.map((group) => (
                            <div
                                key={group.id}
                                className="rounded-lg border p-4"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <p className="font-medium">{group.name}</p>

                                {roles.length === 0 ? (
                                    <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                                        No roles to map onto yet — define one under Roles, or have
                                        an app declare its own.
                                    </p>
                                ) : (
                                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                        {roles.map((role) => (
                                            <Checkbox
                                                key={role.id}
                                                disabled={!mayChange}
                                                checked={group.roleIds.includes(role.id)}
                                                onCheckedChange={(checked) =>
                                                    router.post(
                                                        urls.map,
                                                        {
                                                            group: group.id,
                                                            role: role.id,
                                                            mapped: checked,
                                                        },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                label={role.name}
                                                hint={role.app ?? undefined}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            {mayChange && (
                <>
                    <Panel title="Details">
                        <form
                            className="grid sm:grid-cols-[1fr_auto] gap-2 items-end"
                            onSubmit={(event) => {
                                event.preventDefault();
                                nameForm.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field label="Directory name" error={nameForm.errors.name}>
                                <Input
                                    name="name"
                                    value={nameForm.data.name}
                                    onChange={(event) =>
                                        nameForm.setData('name', event.target.value)
                                    }
                                />
                            </Field>
                            <Button
                                type="submit"
                                variant="primary"
                                className="shrink-0"
                                loading={nameForm.processing}
                            >
                                Save
                            </Button>
                        </form>
                    </Panel>

                    <Panel
                        title={directory.active ? 'Pause provisioning' : 'Resume provisioning'}
                        description={
                            directory.active
                                ? 'Inbound changes stop being applied. Nobody is deactivated by pausing — it simply stops listening.'
                                : 'Inbound changes are applied again from the next sync.'
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {directory.active ? 'Pause' : 'Resume'}
                        </Button>
                    </Panel>

                    {/*
                        Only for a SCIM directory: a pull directory authenticates OUTWARD to
                        the provider's API and has no inbound token to rotate.
                    */}
                    {directory.scim && (
                        <Panel
                            title="Rotate bearer token"
                            description="Issue a fresh token. The one your identity provider holds stops working immediately — update it there before rotating."
                        >
                            <Button size="sm" onClick={() => setConfirming('rotate')}>
                                Rotate token
                            </Button>
                        </Panel>
                    )}

                    <Panel
                        title="Delete directory"
                        description="Provisioning stops and the group mappings go with it. The people it created stay."
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming('delete')}>
                            Delete directory
                        </Button>
                    </Panel>
                </>
            )}

            <ConfirmDelete
                open={confirming === 'rotate'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={directory.name}
                verb="Rotate"
                consequence="The token your identity provider currently holds stops working immediately, and provisioning fails until the new one is pasted in there."
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate, {}, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={directory.name}
                consequence="Provisioning stops immediately and every group mapping is removed. This cannot be undone."
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * The bearer token, shown exactly once.
 *
 * IT BRINGS ITSELF INTO VIEW, and takes focus with it: rotation is triggered from the
 * bottom of the page and reveals the new token at the top, so the only feedback in the
 * viewport was a toast in the opposite corner.
 */
function RevealedToken({ token, endpoint }: { token: string; endpoint: string }) {
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                background: 'var(--warning-soft)',
            }}
        >
            <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                Copy this bearer token now
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Only a hash is stored, so it will not be shown again. Paste it into your identity
                provider beside the endpoint below.
            </p>

            <div className="mt-4 space-y-3">
                <div
                    className="rounded-lg p-3"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        SCIM endpoint
                    </p>
                    <div className="mt-1 flex items-start gap-2">
                        <code className="mono text-sm break-all select-all flex-1">{endpoint}</code>
                        <CopyButton value={endpoint} />
                    </div>
                </div>

                <div
                    className="rounded-lg p-3"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    <p className="text-xs font-semibold" style={{ color: 'var(--warning-strong)' }}>
                        Bearer token — copy it now, it won't be shown again
                    </p>
                    <div className="mt-1 flex items-start gap-2">
                        <code className="mono text-sm break-all select-all flex-1">{token}</code>
                        <CopyButton value={token} variant="primary" label="Copy token" />
                    </div>
                </div>
            </div>
        </div>
    );
}

DirectoryDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
