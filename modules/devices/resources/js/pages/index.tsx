import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import { EmptyState, PageHeader, Pagination, Pill, Table, Td, TdMono, Th } from '@/ui';

interface DeviceRow {
    id: string;
    name: string;
    platform: string;
    status: string;
    active: boolean;
    lastSeen: string | null;
    health: string;
    healthy: boolean;
}

interface PushRow {
    id: string;
    when: string | null;
    kind: string;
    status: string;
    delivered: boolean;
    terminal: boolean;
    attempts: number;
    detail: string | null;
}

type Props = PageProps<{
    devices: DeviceRow[];
    pagination: PaginationState;
    recent: PushRow[];
    /** Null on the environment plane, where the administrator has no handsets of their own. */
    personalPage: string | null;
    wholeEnvironment: boolean;
    help: HelpContent;
}>;

export default function Devices({
    devices,
    pagination,
    recent,
    personalPage,
    wholeEnvironment,
    help,
}: Props) {
    const { t: tc } = useTranslator();

    const scope = wholeEnvironment
        ? tc('console.across_this_environment_ec120044')
        : tc('console.by_this_organization_s_members_a9bf918e');

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={tc('console.handsets_enrolled_in_the_authenticator_app_v_79c194a8', {
                    value0: scope,
                })}
            />

            {/*
                Enrolment is personal, so it lives where every user can reach it — on the plane
                where "every user" means something. An environment administrator is not a
                subject of this environment and has no page here to be sent to, so the link is
                absent rather than broken.
            */}
            {personalPage !== null && (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.anyone_can_enrol_their_own_phone_from_fd09b121')}{' '}
                    <Link href={personalPage} className="underline">
                        {tc('console.account_trusted_devices_07153b2a')}
                    </Link>
                    .
                </p>
            )}

            {devices.length === 0 ? (
                <EmptyState
                    icon="shield"
                    title={tc('console.no_devices_enrolled_yet_a47612e5')}
                    description={tc(
                        'console.a_handset_appears_here_once_somebody_enrols__83d66caa',
                    )}
                />
            ) : (
                <>
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <Table
                                caption={tc(
                                    'console.every_enrolled_handset_when_it_was_last_seen_e9cfa2c4',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{tc('console.device_6ba0bdec')}</Th>
                                        <Th>{tc('console.platform_c78ffe19')}</Th>
                                        <Th>{tc('console.status_920e413c')}</Th>
                                        <Th>{tc('console.last_seen_21fd79c7')}</Th>
                                        <Th>{tc('console.health_55898449')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {devices.map((device) => (
                                        <tr key={device.id}>
                                            <Td className="font-medium">{device.name}</Td>
                                            <Td style={{ color: 'var(--muted-foreground)' }}>
                                                {device.platform}
                                            </Td>
                                            <Td>
                                                <Pill tone={device.active ? 'success' : 'warning'}>
                                                    {device.status}
                                                </Pill>
                                            </Td>
                                            <TdMono className="whitespace-nowrap">
                                                {device.lastSeen ?? '—'}
                                            </TdMono>
                                            <Td
                                                style={{
                                                    color: device.healthy
                                                        ? 'var(--muted-foreground)'
                                                        : 'var(--warning-strong)',
                                                }}
                                            >
                                                {device.health}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>

                    {/*
                        PAGINATED, NOT TRUNCATED. This took the hundred most recently seen and
                        called itself the inventory — an admin looking for a handset enrolled
                        last spring found it absent, with nothing saying the list was cut.
                    */}
                    <Pagination
                        pagination={pagination}
                        noun={tc('console.device_263a4dbe')}
                        pluralNoun={tc('console.devices_ade15a12')}
                        href={(page) =>
                            page > 1
                                ? `${window.location.pathname}?page=${page}`
                                : window.location.pathname
                        }
                    />
                </>
            )}

            <div>
                <h2 className="mb-3 text-sm font-medium">
                    {tc('console.recent_notifications_11bc0414')}
                </h2>

                {recent.length === 0 ? (
                    <EmptyState
                        icon="mail"
                        title={tc('console.nothing_sent_yet_be270600')}
                        description={tc(
                            'console.approval_prompts_and_sign_in_alerts_appear_h_673f43a3',
                        )}
                    />
                ) : (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <Table
                                caption={tc(
                                    'console.the_most_recent_push_notifications_sent_to_t_cf468f18',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{tc('console.when_cf9c7aa2')}</Th>
                                        <Th>{tc('console.kind_f5387f9b')}</Th>
                                        <Th>{tc('console.status_920e413c')}</Th>
                                        <Th>{tc('console.attempts_06e70139')}</Th>
                                        <Th>{tc('console.detail_fb5f27d5')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent.map((push) => (
                                        <tr key={push.id}>
                                            <TdMono className="whitespace-nowrap">
                                                {push.when ?? '—'}
                                            </TdMono>
                                            <Td>{push.kind}</Td>
                                            <Td>
                                                <Pill
                                                    tone={
                                                        push.delivered
                                                            ? 'success'
                                                            : push.terminal
                                                              ? 'destructive'
                                                              : 'warning'
                                                    }
                                                >
                                                    {push.status}
                                                </Pill>
                                            </Td>
                                            <TdMono className="tabular-nums">
                                                {push.attempts}
                                            </TdMono>
                                            <Td style={{ color: 'var(--muted-foreground)' }}>
                                                {push.detail ?? '—'}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

Devices.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
