import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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

const MANAGER = (tc: Translator['t']): Record<ManagerState, { label: string; tone: PillTone }> => ({
    running: { label: tc('console.running_f4ccae29'), tone: 'success' },
    missing: { label: tc('console.not_running_9e3856ee'), tone: 'destructive' },
    'not-supervised': { label: tc('console.not_supervising_d784c9f5'), tone: 'neutral' },
});

const QUEUE = (tc: Translator['t']): Record<QueueStatus, { label: string; tone: PillTone }> => ({
    ok: { label: tc('console.within_sla_32992c79'), tone: 'success' },
    behind: { label: tc('console.behind_a2e92848'), tone: 'destructive' },
    unreadable: { label: tc('console.unreadable_89e45653'), tone: 'warning' },
});

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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const managerState = MANAGER(tc)[manager.state];

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.whether_the_background_work_of_this_install__0647da12')}
                actions={
                    monitorHref !== null && (
                        // A full navigation, not an Inertia visit: the job monitor is its own
                        // page, not one the console draws.
                        <Button asChild variant="secondary">
                            <a href={monitorHref}>
                                <Icon name="external" className="w-4 h-4" aria-hidden="true" />
                                {tc('console.open_job_monitor_4de98d41')}
                            </a>
                        </Button>
                    )
                }
            />

            {!healthy && problems.length > 0 && (
                <div className="mt-8">
                    <Panel title={tc('console.needs_attention_c1ebc781')}>
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
                    title={tc('console.queue_manager_735b901f')}
                    description={tc(
                        'console.one_long_running_process_php_artisan_queue_a_9e4a3b67',
                    )}
                    action={<Pill tone={managerState.tone}>{managerState.label}</Pill>}
                >
                    <KvList>
                        <Kv label={tc('console.last_heard_from_4f0b5efc')}>
                            {manager.secondsSinceBeat === null
                                ? tc('console.never_6300ef80')
                                : tc('console.value0_ago_d57ad337', {
                                      value0: age(manager.secondsSinceBeat),
                                  })}
                        </Kv>
                        <Kv label={tc('console.host_4a823118')}>{manager.host ?? '—'}</Kv>
                        <Kv label={tc('console.counts_as_stopped_after_f1784b53')} prose>
                            {age(manager.staleAfterSeconds)}
                            {tc('console.of_silence_a2190281')}
                        </Kv>
                    </KvList>
                </Panel>
            </div>

            <Panel title={tc('console.queues_be77db11')} flush>
                {queues.length === 0 ? (
                    <EmptyState
                        icon="clock"
                        title={tc('console.nothing_is_queued_on_this_install_f725946e')}
                        description={tc(
                            'console.every_job_runs_as_soon_as_it_is_dispatched_s_5504c897',
                        )}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table
                            caption={tc(
                                'console.every_queue_this_install_sends_background_wo_562c4113',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{tc('console.queue_3b2fe03e')}</Th>
                                    <Th className="text-right">{tc('console.waiting_6e293a8c')}</Th>
                                    <Th className="text-right">
                                        {tc('console.oldest_wait_4ca4b3a9')}
                                    </Th>
                                    <Th className="text-right">
                                        {tc('console.pickup_sla_f6f6a98a')}
                                    </Th>
                                    <Th>{tc('console.status_920e413c')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {queues.map((queue) => {
                                    const status = QUEUE(tc)[queue.status];

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
                                                {queue.pending.toLocaleString(locale)}
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
