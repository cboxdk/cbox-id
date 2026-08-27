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
    const scope = wholeEnvironment ? ' across this environment' : " by this organization's members";

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={`Handsets enrolled in the authenticator app${scope}. These receive approval prompts and sign-in alerts. Push tokens are never shown.`}
            />

            {/*
                Enrolment is personal, so it lives where every user can reach it — on the plane
                where "every user" means something. An environment administrator is not a
                subject of this environment and has no page here to be sent to, so the link is
                absent rather than broken.
            */}
            {personalPage !== null && (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Anyone can enrol their own phone from{' '}
                    <Link href={personalPage} className="underline">
                        My account → Trusted devices
                    </Link>
                    .
                </p>
            )}

            {devices.length === 0 ? (
                <EmptyState
                    icon="shield"
                    title="No devices enrolled yet"
                    description="A handset appears here once somebody enrols it in the authenticator app."
                />
            ) : (
                <>
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <Table caption="Every enrolled handset, when it was last seen, and whether its pushes are getting through">
                                <thead>
                                    <tr>
                                        <Th>Device</Th>
                                        <Th>Platform</Th>
                                        <Th>Status</Th>
                                        <Th>Last seen</Th>
                                        <Th>Health</Th>
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
                        noun="device"
                        href={(page) =>
                            page > 1
                                ? `${window.location.pathname}?page=${page}`
                                : window.location.pathname
                        }
                    />
                </>
            )}

            <div>
                <h2 className="mb-3 text-sm font-medium">Recent notifications</h2>

                {recent.length === 0 ? (
                    <EmptyState
                        icon="mail"
                        title="Nothing sent yet"
                        description="Approval prompts and sign-in alerts appear here as they are delivered."
                    />
                ) : (
                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                            <Table caption="The most recent push notifications sent to these devices, and why any of them failed">
                                <thead>
                                    <tr>
                                        <Th>When</Th>
                                        <Th>Kind</Th>
                                        <Th>Status</Th>
                                        <Th>Attempts</Th>
                                        <Th>Detail</Th>
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
