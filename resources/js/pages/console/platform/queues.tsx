import { useTranslator as useFormatTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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

const MANAGER = (t: Translator['t']): Record<ManagerState, { label: string; tone: PillTone }> => ({
    running: { label: t('console.running_f4ccae29'), tone: 'success' },
    missing: { label: t('console.not_running_9e3856ee'), tone: 'destructive' },
    'not-supervised': { label: t('console.not_supervising_d784c9f5'), tone: 'neutral' },
});

const QUEUE = (t: Translator['t']): Record<QueueStatus, { label: string; tone: PillTone }> => ({
    ok: { label: t('console.within_sla_32992c79'), tone: 'success' },
    behind: { label: t('console.behind_a2e92848'), tone: 'destructive' },
    unreadable: { label: t('console.unreadable_89e45653'), tone: 'warning' },
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
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const managerState = MANAGER(consoleOptionText)[manager.state];

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.whether_the_background_work_of_this_install_webhooks_si_0647da12',
                )}
                actions={
                    monitorHref !== null && (
                        // A full navigation, not an Inertia visit: the job monitor is its own
                        // page, not one the console draws.
                        <Button asChild variant="secondary">
                            <a href={monitorHref}>
                                {consoleRich('console._part1_open_job_monitor_95c1af66', {
                                    part1: (
                                        <Icon
                                            name="external"
                                            className="w-4 h-4"
                                            aria-hidden="true"
                                        />
                                    ),
                                })}
                            </a>
                        </Button>
                    )
                }
            />

            {!healthy && problems.length > 0 && (
                <div className="mt-8">
                    <Panel title={consoleT('console.needs_attention_c1ebc781')}>
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
                    title={consoleT('console.queue_manager_735b901f')}
                    description={consoleT(
                        'console.one_long_running_process_php_artisan_queue_autoscale_st_9e4a3b67',
                    )}
                    action={<Pill tone={managerState.tone}>{managerState.label}</Pill>}
                >
                    <KvList>
                        <Kv label={consoleT('console.last_heard_from_4f0b5efc')}>
                            {manager.secondsSinceBeat === null
                                ? consoleText('console.never_6300ef80')
                                : consoleFinalText('console._part1_ago_2e7ad2cf', {
                                      part1: age(manager.secondsSinceBeat),
                                  })}
                        </Kv>
                        <Kv label={consoleT('console.host_4a823118')}>{manager.host ?? '—'}</Kv>
                        <Kv label={consoleT('console.counts_as_stopped_after_f1784b53')} prose>
                            {consoleRich('console._part1_of_silence_9817dc4e', {
                                part1: age(manager.staleAfterSeconds),
                            })}
                        </Kv>
                    </KvList>
                </Panel>
            </div>

            <Panel title={consoleT('console.queues_be77db11')} flush>
                {queues.length === 0 ? (
                    <EmptyState
                        icon="clock"
                        title={consoleT('console.nothing_is_queued_on_this_install_f725946e')}
                        description={consoleT(
                            'console.every_job_runs_as_soon_as_it_is_dispatched_so_there_is_5504c897',
                        )}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <Table
                            caption={consoleUiText(
                                'console.every_queue_this_install_sends_background_work_through_562c4113',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{consoleT('console.queue_3b2fe03e')}</Th>
                                    <Th className="text-right">
                                        {consoleT('console.waiting_6e293a8c')}
                                    </Th>
                                    <Th className="text-right">
                                        {consoleT('console.oldest_wait_4ca4b3a9')}
                                    </Th>
                                    <Th className="text-right">
                                        {consoleT('console.pickup_sla_f6f6a98a')}
                                    </Th>
                                    <Th>{consoleT('console.status_920e413c')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {queues.map((queue) => {
                                    const status = QUEUE(consoleOptionText)[queue.status];

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
                                                {queue.pending.toLocaleString(consoleLocale)}
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
