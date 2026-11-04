import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    ConfirmDelete,
    CopyButton,
    Kv,
    KvList,
    Panel,
    Pill,
} from '@/ui';

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

export default function OutboundSyncDetail({ connection, mayAdminister, indexHref, urls }: Props) {
    const { t: tc } = useTranslator();

    const [confirming, setConfirming] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={tc('console.outbound_provisioning_e49a114e')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{connection.name}</h1>
                    <Pill tone={connection.active ? 'success' : 'warning'}>
                        {connection.active
                            ? tc('console.active_92340695')
                            : tc('console.paused_e159b061')}
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
                <Panel title={tc('console.last_push_failed_1c6e3894')}>
                    <p className="text-sm mono" style={{ color: 'var(--destructive)' }}>
                        {connection.lastError}
                    </p>
                </Panel>
            )}

            <Panel title={tc('console.connection_639a40e8')}>
                <div className="space-y-4">
                    <div>
                        <p className="label">{tc('console.scim_base_url_be8fb61f')}</p>
                        <div className="mt-1 flex items-center gap-2">
                            <code className="flex-1 min-w-0 truncate mono text-sm">
                                {connection.baseUrl}
                            </code>
                            <CopyButton value={connection.baseUrl} />
                        </div>
                    </div>

                    <KvList>
                        <Kv label={tc('console.auth_scheme_4a894695')} prose>
                            <Badge>{connection.scheme}</Badge>
                        </Kv>
                        <Kv label={tc('console.scope_b073f6c6')} prose>
                            <Badge>
                                {connection.scope ?? tc('console.environment_wide_cd1204b6')}
                            </Badge>
                        </Kv>
                    </KvList>
                </div>
            </Panel>

            {mayAdminister && (
                <>
                    <Panel
                        title={
                            connection.active
                                ? tc('console.pause_connection_722c1867')
                                : tc('console.resume_connection_62e052c5')
                        }
                        description={
                            connection.active
                                ? tc(
                                      'console.changes_stop_being_pushed_downstream_nobody__1c6c2cc0',
                                  )
                                : tc(
                                      'console.changes_are_pushed_again_from_the_next_one_w_ae74fe1b',
                                  )
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {connection.active
                                ? tc('console.pause_858e4ba7')
                                : tc('console.resume_d640c742')}
                        </Button>
                    </Panel>

                    <Panel
                        title={tc('console.delete_connection_e960651b')}
                        description={tc(
                            'console.provisioning_to_the_downstream_app_stops_imm_948dd5a5',
                        )}
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            {tc('console.delete_connection_e960651b')}
                        </Button>
                    </Panel>
                </>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={connection.name}
                consequence={tc('console.provisioning_to_the_downstream_app_stops_imm_6d193c25')}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

OutboundSyncDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
