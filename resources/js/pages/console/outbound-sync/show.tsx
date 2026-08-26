import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, ConfirmDelete, CopyButton, Icon, Kv, KvList, Panel, Pill } from '@/ui';

type Props = PageProps<{
    connection: {
        id: string;
        name: string;
        baseUrl: string;
        scheme: string;
        /** The organization it provisions, or null when it covers the whole environment. */
        scope: string | null;
        active: boolean;
        lastError: string | null;
    };
    mayAdminister: boolean;
    indexHref: string;
    urls: { toggle: string; destroy: string };
}>;

export default function OutboundSyncDetail({
    connection,
    mayAdminister,
    indexHref,
    urls,
}: Props) {
    const [confirming, setConfirming] = useState(false);

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
                    Sync users out
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{connection.name}</h1>
                    <Pill tone={connection.active ? 'success' : 'warning'}>
                        {connection.active ? 'Active' : 'Paused'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {connection.id}
                </p>
            </div>

            {/*
                A failing push means people are drifting out of step downstream, so it is
                the first thing on the page rather than a field in a details list.
            */}
            {connection.lastError !== null && (
                <Panel title="Last push failed">
                    <p className="text-sm mono" style={{ color: 'var(--destructive)' }}>
                        {connection.lastError}
                    </p>
                </Panel>
            )}

            <Panel title="Connection">
                <div className="space-y-4">
                    <div>
                        <p className="label">SCIM base URL</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {connection.baseUrl}
                            </code>
                            <CopyButton value={connection.baseUrl} />
                        </div>
                    </div>

                    <KvList>
                        <Kv label="Auth scheme" prose>
                            <Badge>{connection.scheme}</Badge>
                        </Kv>
                        <Kv label="Scope" prose>
                            <Badge>{connection.scope ?? 'Environment-wide'}</Badge>
                        </Kv>
                    </KvList>
                </div>
            </Panel>

            {mayAdminister && (
                <>
                    <Panel
                        title={connection.active ? 'Pause connection' : 'Resume connection'}
                        description={
                            connection.active
                                ? 'Changes stop being pushed downstream. Nobody is removed there — it simply stops sending.'
                                : 'Changes are pushed again from the next one. What happened while it was paused is not replayed.'
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {connection.active ? 'Pause' : 'Resume'}
                        </Button>
                    </Panel>

                    <Panel
                        title="Delete connection"
                        description="Provisioning to the downstream app stops immediately. The people it already created there stay."
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            Delete connection
                        </Button>
                    </Panel>
                </>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={connection.name}
                consequence="Provisioning to the downstream app stops immediately. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

OutboundSyncDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
