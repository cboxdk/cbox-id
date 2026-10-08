import { Link, useForm } from '@inertiajs/react';
import { type MessageKey, useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, type IconName, Icon, PageHeader, Pill, Progress } from '@/ui';
import type { PortalChrome } from './parts';

type Intent =
    'sso' | 'dsync' | 'domain_verification' | 'log_streams' | 'certificate_renewal' | 'audit_logs';

interface Task {
    intent: Intent;
    href: string;
    steps: { key: string; done: boolean }[];
    done: boolean;
    started: boolean;
}

type Props = PageProps<{
    portal: PortalChrome;
    tasks: Task[];
    finishHref: string;
}>;

/** Each intent's words and mark — keyed, so a new intent is a type error until it has them. */
const INTENTS: Record<Intent, { title: MessageKey; description: MessageKey; icon: IconName }> = {
    sso: {
        title: 'portal.intents.sso.title',
        description: 'portal.intents.sso.description',
        icon: 'connections',
    },
    dsync: {
        title: 'portal.intents.dsync.title',
        description: 'portal.intents.dsync.description',
        icon: 'directory',
    },
    domain_verification: {
        title: 'portal.intents.domain_verification.title',
        description: 'portal.intents.domain_verification.description',
        icon: 'shield-check',
    },
    log_streams: {
        title: 'portal.intents.log_streams.title',
        description: 'portal.intents.log_streams.description',
        icon: 'audit',
    },
    certificate_renewal: {
        title: 'portal.intents.certificate_renewal.title',
        description: 'portal.intents.certificate_renewal.description',
        icon: 'key',
    },
    audit_logs: {
        title: 'portal.intents.audit_logs.title',
        description: 'portal.intents.audit_logs.description',
        icon: 'eye',
    },
};

const STEPS: Record<string, MessageKey> = {
    connection_created: 'portal.setup.steps.connection_created',
    domain_verified: 'portal.setup.steps.domain_verified',
    connection_active: 'portal.setup.steps.connection_active',
    directory_created: 'portal.setup.steps.directory_created',
    directory_synced: 'portal.setup.steps.directory_synced',
    domain_added: 'portal.setup.steps.domain_added',
    stream_created: 'portal.setup.steps.stream_created',
    stream_delivering: 'portal.setup.steps.stream_delivering',
    certificate_staged: 'portal.setup.steps.certificate_staged',
    certificate_current: 'portal.setup.steps.certificate_current',
    audit_logs_viewed: 'portal.setup.steps.audit_logs_viewed',
};

/**
 * THE ADMIN PORTAL'S HOME — what this link asks of its holder, as a checklist.
 *
 * One card per task the link covers, each with its own steps ticked off from the state of
 * the system, so somebody who comes back after their DNS change went live sees where they
 * left off. Finishing closes the link for good, so it says so beside the button.
 */
export default function PortalSetup({ portal, tasks, finishHref }: Props) {
    const finish = useForm({});
    const { t } = useTranslator();

    return (
        <div>
            <PageHeader
                eyebrow={null}
                title={
                    portal.organizationName === null
                        ? t('portal.setup.heading')
                        : t('portal.setup.heading_for', { organization: portal.organizationName })
                }
                description={t('portal.setup.description')}
            />

            {tasks.length === 0 ? (
                <p className="card p-5 text-sm">{t('portal.setup.empty')}</p>
            ) : (
                <ol className="space-y-3 mb-8">
                    {tasks.map((task) => (
                        <TaskCard key={task.intent} task={task} />
                    ))}
                </ol>
            )}

            <div
                className="flex flex-wrap items-center justify-between gap-3 border-t pt-5"
                style={{ borderColor: 'var(--border)' }}
            >
                <p
                    className="text-xs"
                    style={{ color: 'var(--muted-foreground)', maxWidth: '28rem' }}
                >
                    {t('portal.setup.finish_hint')}
                </p>
                <Button
                    variant="primary"
                    loading={finish.processing}
                    onClick={() => finish.post(finishHref)}
                >
                    <Icon name="check" className="w-4 h-4" /> {t('portal.setup.finish')}
                </Button>
            </div>
        </div>
    );
}

function TaskCard({ task }: { task: Task }) {
    const { t } = useTranslator();
    const intent = INTENTS[task.intent];
    const done = task.steps.filter((step) => step.done).length;
    const total = task.steps.length;
    const status = task.done ? 'done' : task.started ? 'in_progress' : 'not_started';
    const title = t(intent.title);

    return (
        <li className="card p-5">
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className="inline-flex items-center justify-center rounded-lg shrink-0"
                    style={{
                        width: '2.25rem',
                        height: '2.25rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent)',
                    }}
                >
                    <Icon name={intent.icon} className="w-4 h-4" />
                </span>
                <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">{title}</h2>
                        <Pill tone={task.done ? 'success' : task.started ? 'info' : 'neutral'}>
                            {t(`portal.setup.status.${status}`)}
                        </Pill>
                    </div>
                    <p className="text-sm mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        {t(intent.description)}
                    </p>

                    <div className="mt-3 flex items-center gap-3">
                        <Progress
                            percent={total === 0 ? 0 : (done / total) * 100}
                            label={`${title}: ${t('portal.setup.progress', { done, total })}`}
                            className="flex-1"
                        />
                        <span
                            className="text-xs shrink-0"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {t('portal.setup.progress', { done, total })}
                        </span>
                    </div>

                    <ul className="mt-3 space-y-1">
                        {task.steps.map((step) => {
                            const key = STEPS[step.key];

                            return (
                                <li key={step.key} className="flex items-center gap-2 text-sm">
                                    <Icon
                                        name={step.done ? 'check' : 'clock'}
                                        className="w-3.5 h-3.5"
                                        style={{
                                            color: step.done ? 'var(--success)' : 'var(--faint)',
                                        }}
                                    />
                                    <span
                                        style={{
                                            color: step.done
                                                ? 'var(--foreground)'
                                                : 'var(--muted-foreground)',
                                        }}
                                    >
                                        {key === undefined ? step.key : t(key)}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
                <Link
                    href={task.href}
                    className="btn btn-secondary shrink-0"
                    aria-label={`${title}: ${t(task.done ? 'portal.setup.review' : task.started ? 'portal.setup.continue' : 'portal.setup.start')}`}
                >
                    {t(
                        task.done
                            ? 'portal.setup.review'
                            : task.started
                              ? 'portal.setup.continue'
                              : 'portal.setup.start',
                    )}
                </Link>
            </div>
        </li>
    );
}

PortalSetup.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
