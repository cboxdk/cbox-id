import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    EmptyState,
    Icon,
    Kv,
    KvList,
    PageHeader,
    Panel,
    Pill,
    type PillTone,
    Table,
    Td,
    TdMono,
    Th,
} from '@/ui';

type ManagerState = 'running' | 'missing' | 'not-supervised';
type QueueStatus = 'ok' | 'behind' | 'unreadable';

interface Manager {
    state: ManagerState;
    lastBeatAt: string | null;
    secondsSinceBeat: number | null;
    host: string | null;
    staleAfterSeconds: number;
}

interface QueueRow {
    connection: string;
    queue: string;
    pending: number;
    oldestWaitSeconds: number | null;
    slaSeconds: number;
    status: QueueStatus;
    unreadable: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    healthy: boolean;
    manager: Manager;
    queues: QueueRow[];
    problems: string[];
    monitorHref: string | null;
}>;

const MANAGER: Record<ManagerState, { label: string; tone: PillTone }> = {
    running: { label: 'Running', tone: 'success' },
    missing: { label: 'Not running', tone: 'destructive' },
    'not-supervised': { label: 'Not supervising', tone: 'neutral' },
};

const QUEUE: Record<QueueStatus, { label: string; tone: PillTone }> = {
    ok: { label: 'Within SLA', tone: 'success' },
    behind: { label: 'Behind', tone: 'destructive' },
    unreadable: { label: 'Unreadable', tone: 'warning' },
};

/** "42s", "3m 5s", "2h 10m" — an age a person reads at a glance. */
function age(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
    }

    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

export default function PlatformQueues({
    help,
    healthy,
    manager,
    queues,
    problems,
    monitorHref,
}: Props) {
    const managerState = MANAGER[manager.state];

    return (
        <>
            <PageHeader
                help={help}
                description="Whether the background work of this install — webhooks, sign-out notices to connected apps, queued email — is actually being sent."
                actions={
                    monitorHref !== null && (
                        // A full navigation, not an Inertia visit: the job monitor is its own
                        // page, not one the console draws.
                        <Button asChild variant="secondary">
                            <a href={monitorHref}>
                                <Icon name="external" className="w-4 h-4" aria-hidden="true" />
                                Open job monitor
                            </a>
                        </Button>
                    )
                }
            />

            {!healthy && problems.length > 0 && (
                <div className="mt-8">
                    <Panel title="Needs attention">
                        {/* The alert wraps the list rather than being it: a <ul> given a role
                            stops being a list, and its items are then orphaned. */}
                        <div role="alert">
                            <ul className="space-y-2 text-sm">
                                {problems.map((problem) => (
                                    <li key={problem} className="flex gap-2">
                                        <Icon
                                            name="warning"
                                            className="w-4 h-4 shrink-0 mt-0.5"
                                            style={{ color: 'var(--destructive)' }}
                                            aria-hidden="true"
                                        />
                                        <span>{problem}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Panel>
                </div>
            )}

            <div className="mt-8 mb-5">
                <Panel
                    title="Queue manager"
                    description="One long-running process, php artisan queue:autoscale, starts and stops the workers that send queued work."
                    action={<Pill tone={managerState.tone}>{managerState.label}</Pill>}
                >
                    <KvList>
                        <Kv label="Last heard from">
                            {manager.secondsSinceBeat === null
                                ? 'Never'
                                : `${age(manager.secondsSinceBeat)} ago`}
                        </Kv>
                        <Kv label="Host">{manager.host ?? '—'}</Kv>
                        <Kv label="Counts as stopped after" prose>
                            {age(manager.staleAfterSeconds)} of silence
                        </Kv>
                    </KvList>
                </Panel>
            </div>

            <Panel title="Queues" flush>
                {queues.length === 0 ? (
                    <EmptyState
                        icon="clock"
                        title="Nothing is queued on this install"
                        description="Every job runs as soon as it is dispatched, so there is no queue to watch."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption="Every queue this install sends background work through, and how long its oldest job has waited">
                            <thead>
                                <tr>
                                    <Th>Queue</Th>
                                    <Th className="text-right">Waiting</Th>
                                    <Th className="text-right">Oldest wait</Th>
                                    <Th className="text-right">Pickup SLA</Th>
                                    <Th>Status</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {queues.map((queue) => {
                                    const status = QUEUE[queue.status];

                                    return (
                                        <tr key={`${queue.connection}:${queue.queue}`}>
                                            <Td>
                                                <p className="font-medium mono">{queue.queue}</p>
                                                <p
                                                    className="text-xs mono"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    {queue.connection}
                                                </p>
                                            </Td>
                                            <TdMono className="text-right tabular-nums">
                                                {queue.pending.toLocaleString()}
                                            </TdMono>
                                            <TdMono className="text-right tabular-nums">
                                                {queue.oldestWaitSeconds === null
                                                    ? '—'
                                                    : age(queue.oldestWaitSeconds)}
                                            </TdMono>
                                            <TdMono className="text-right tabular-nums">
                                                {age(queue.slaSeconds)}
                                            </TdMono>
                                            <Td>
                                                <Pill tone={status.tone}>{status.label}</Pill>
                                                {queue.unreadable !== null && (
                                                    <p
                                                        className="text-xs mt-1"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {queue.unreadable}
                                                    </p>
                                                )}
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>
        </>
    );
}

PlatformQueues.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
