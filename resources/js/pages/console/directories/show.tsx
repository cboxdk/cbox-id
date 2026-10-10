import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
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
    type PillTone,
    Select,
    Textarea,
} from '@/ui';

interface Group {
    id: string;
    name: string;
    roleIds: string[];
}

interface SetupCredential {
    key: string;
    label: string;
    help: string;
    example: string;
    secret: boolean;
    required: boolean;
}

interface SyncFailure {
    external_id: string | null;
    reason: string;
}

/** How a pull directory's sync is going — the same presenter the API answers with. */
interface SyncState {
    status: 'running' | 'succeeded' | 'partial' | 'failed' | null;
    startedAt: string | null;
    syncedAt: string | null;
    nextAt: string | null;
    intervalMinutes: number | null;
    stats: {
        mode: 'full' | 'incremental';
        provisioned: number;
        deprovisioned: number;
        groups: number;
        skipped: number;
        failed: number;
        failures: SyncFailure[];
    } | null;
    customAttributes: string[];
    active: boolean;
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
        pull: boolean;
        hris: boolean;
        sync: SyncState | null;
    };
    setup: {
        steps: string[];
        docs: string;
        incremental: boolean;
        credentials: SetupCredential[];
    } | null;
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
        sync: string;
        syncSettings: string;
        credentials: string;
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
                <Breadcrumb href={indexHref} label="Directory Sync" />
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

            {directory.sync !== null && (
                <SyncPanel
                    sync={directory.sync}
                    incremental={setup?.incremental === true}
                    mayChange={mayChange}
                    href={urls.sync}
                />
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

                    {directory.sync !== null && (
                        <SyncSettings
                            sync={directory.sync}
                            hris={directory.hris}
                            href={urls.syncSettings}
                        />
                    )}

                    {directory.hris && setup !== null && (
                        <ReplaceCredentials
                            providerLabel={directory.providerLabel}
                            credentials={setup.credentials}
                            href={urls.credentials}
                        />
                    )}

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

const STATUS: Record<NonNullable<SyncState['status']>, { label: string; tone: PillTone }> = {
    running: { label: 'Syncing', tone: 'info' },
    succeeded: { label: 'Synced', tone: 'success' },
    partial: { label: 'Synced with problems', tone: 'warning' },
    failed: { label: 'Failed', tone: 'destructive' },
};

function when(iso: string | null): string {
    return iso === null ? '—' : new Date(iso).toLocaleString();
}

/**
 * HOW THE SYNC IS GOING: the last run's outcome and counts, when the next one is due, and
 * the records it could not reconcile — by the provider's own employee id, which is what an
 * administrator can look up there. "Sync now" queues a pull; an incremental HR system can
 * also be asked for everybody, the only run that deprovisions people who disappeared.
 */
function SyncPanel({
    sync,
    incremental,
    mayChange,
    href,
}: {
    sync: SyncState;
    incremental: boolean;
    mayChange: boolean;
    href: string;
}) {
    const status = sync.status === null ? null : STATUS[sync.status];
    const stats = sync.stats;

    return (
        <Panel
            title="Sync"
            description={
                sync.intervalMinutes === null
                    ? undefined
                    : `Pulled every ${sync.intervalMinutes} minutes${incremental ? ', asking only for what changed between daily full pulls' : ''}.`
            }
            action={
                mayChange && sync.active ? (
                    <div className="flex gap-2 shrink-0">
                        {incremental && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(href, { full: true }, { preserveScroll: true })
                                }
                            >
                                Full sync
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="primary"
                            onClick={() => router.post(href, {}, { preserveScroll: true })}
                        >
                            Sync now
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span>
                    {status === null ? (
                        <Pill tone="neutral">Not synced yet</Pill>
                    ) : (
                        <Pill tone={status.tone}>{status.label}</Pill>
                    )}
                </span>
                <span style={{ color: 'var(--muted-foreground)' }}>
                    Last run {when(sync.startedAt)}
                </span>
                <span style={{ color: 'var(--muted-foreground)' }}>Next {when(sync.nextAt)}</span>
            </div>

            {stats !== null && (
                <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {stats.mode === 'incremental' ? 'Changes only: ' : 'Everybody: '}
                    {stats.provisioned} up to date, {stats.deprovisioned} deprovisioned,{' '}
                    {stats.skipped} skipped, {stats.groups} groups
                    {stats.failed > 0 ? `, ${stats.failed} could not be synced` : ''}.
                </p>
            )}

            {stats !== null && stats.failures.length > 0 && (
                <ul className="mt-3 space-y-1 text-sm">
                    {stats.failures.map((failure) => (
                        <li key={`${failure.external_id ?? 'run'}: ${failure.reason}`}>
                            {failure.external_id !== null && (
                                <code className="mono text-xs mr-2">{failure.external_id}</code>
                            )}
                            {failure.reason}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

const INTERVALS = ['15', '30', '60', '120', '240', '720', '1440'] as const;

/** The pace, and — for an HR system — the fields copied onto people verbatim. */
function SyncSettings({ sync, hris, href }: { sync: SyncState; hris: boolean; href: string }) {
    const form = useForm({
        interval: String(sync.intervalMinutes ?? 60),
        customAttributes: sync.customAttributes.join('\n'),
    });

    return (
        <Panel
            title="Sync settings"
            description={
                hris
                    ? "How often to pull, and which of the HR system's own fields to copy onto each person — a cost centre, a location. Changing the fields makes the next run a full one."
                    : 'How often to pull.'
            }
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <Field label="Pull every" error={form.errors.interval}>
                    <Select
                        name="interval"
                        value={form.data.interval}
                        onValueChange={(value) => form.setData('interval', value)}
                        options={INTERVALS.map((minutes) => ({
                            value: minutes,
                            label:
                                Number(minutes) < 60
                                    ? `${minutes} minutes`
                                    : Number(minutes) === 60
                                      ? 'hour'
                                      : `${Number(minutes) / 60} hours`,
                        }))}
                    />
                </Field>
                {hris && (
                    <Field
                        label="Fields to pass through"
                        hint="The HR system's own field names, one per line."
                        error={form.errors.customAttributes}
                    >
                        <Textarea
                            name="customAttributes"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            value={form.data.customAttributes}
                            onChange={(event) =>
                                form.setData('customAttributes', event.target.value)
                            }
                        />
                    </Field>
                )}
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    Save
                </Button>
            </form>
        </Panel>
    );
}

/**
 * NEW CREDENTIALS without reconnecting — the key was rotated, the secret expired. The fields
 * are the HR system's own, from the framework's catalogue; they are verified against it
 * before the old ones are replaced, and never shown again.
 */
function ReplaceCredentials({
    providerLabel,
    credentials,
    href,
}: {
    providerLabel: string;
    credentials: SetupCredential[];
    href: string;
}) {
    const form = useForm<{ credentials: Record<string, string> }>({ credentials: {} });
    const credentialError = usePage().props.errors.credentials;

    return (
        <Panel
            title="Replace credentials"
            description={`New ${providerLabel} credentials, checked against ${providerLabel} before the current ones are replaced.`}
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(href, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                {credentials.map((credential, index) => (
                    <Field
                        key={credential.key}
                        label={credential.label + (credential.required ? '' : ' (optional)')}
                        hint={credential.help}
                        error={index === 0 ? credentialError : undefined}
                    >
                        <Input
                            name={`credentials.${credential.key}`}
                            type={credential.secret ? 'password' : 'text'}
                            className="mono"
                            autoComplete="off"
                            placeholder={credential.example}
                            value={form.data.credentials[credential.key] ?? ''}
                            onChange={(event) =>
                                form.setData('credentials', {
                                    ...form.data.credentials,
                                    [credential.key]: event.target.value,
                                })
                            }
                        />
                    </Field>
                ))}
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    Verify and replace
                </Button>
            </form>
        </Panel>
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
