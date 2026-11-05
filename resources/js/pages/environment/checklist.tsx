import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { Button, Disclosure, Icon, Pill, Progress } from '@/ui';

/** `App\Platform\Onboarding\EnvironmentChecklist::toProps()` */
export interface ChecklistStep {
    key: string;
    title: string;
    description: string;
    actionLabel: string;
    href: string | null;
    done: boolean;
    optional: boolean;
}

export interface ChecklistProps {
    steps: ChecklistStep[];
    completed: number;
    total: number;
    percent: number;
    isComplete: boolean;
    next: string | null;
}

/**
 * The environment's "Get started" list — each step ticked by what the environment holds,
 * never by hand. Used whole on the Get started page and, `compact`, on top of Overview,
 * where only the steps still to do are listed.
 */
export function EnvironmentChecklist({
    checklist,
    compact = false,
    href,
    onDismiss,
}: {
    checklist: ChecklistProps;
    compact?: boolean;
    /** Where "See all" goes, on the compact card. */
    href?: string;
    onDismiss?: () => void;
}) {
    const { t: tc } = useTranslator();

    const required = checklist.steps.filter((step) => !step.optional);
    const optional = checklist.steps.filter((step) => step.optional);
    const steps = compact ? required.filter((step) => !step.done).slice(0, 1) : required;

    return (
        <section className="card p-5" aria-labelledby="environment-checklist-title">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id="environment-checklist-title" className="font-semibold">
                    {tc(checklist.isComplete ? 'console.ux_setup_ready' : 'console.ux_first_login')}
                </h2>
                <div className="flex flex-1 items-center gap-3 min-w-[10rem]">
                    <Progress
                        percent={checklist.percent}
                        label={tc('console.setup_progress_e9b4495a')}
                    />
                    <span
                        className="text-sm mono shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {checklist.completed}
                        {tc('console.of_a4282e4b')}
                        {checklist.total}
                    </span>
                </div>
                {compact && (
                    <div className="flex items-center gap-2">
                        {href !== undefined && (
                            <Button asChild size="sm">
                                <Link href={href}>{tc('console.see_all_d7a8c446')}</Link>
                            </Button>
                        )}
                        {onDismiss !== undefined && (
                            <Button size="sm" variant="ghost" onClick={onDismiss}>
                                {tc('console.hide_ac20a57b')}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {checklist.isComplete && (
                <p className="mt-2 text-sm text-[var(--muted-foreground)]">
                    {tc('console.ux_setup_ready_help')}
                </p>
            )}
            {checklist.next !== null && (
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.next_up_1eab46b7')}{' '}
                    <span style={{ color: 'var(--foreground)', fontWeight: 500 }}>
                        {checklist.next}
                    </span>
                </p>
            )}

            <ol className="mt-4 space-y-3">
                {steps.map((step) => (
                    <li
                        key={step.key}
                        className="flex items-start gap-3"
                        data-step={step.key}
                        data-done={step.done}
                    >
                        <span
                            className="grid place-items-center rounded-full shrink-0 mt-0.5"
                            style={{
                                width: '1.5rem',
                                height: '1.5rem',
                                ...(step.done
                                    ? {
                                          background: 'var(--success-soft)',
                                          color: 'var(--success-strong)',
                                      }
                                    : {
                                          border: '1px solid var(--border)',
                                          color: 'var(--muted-foreground)',
                                      }),
                            }}
                            aria-hidden="true"
                        >
                            {step.done && <Icon name="check" className="w-3.5 h-3.5" />}
                        </span>

                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3
                                    className="text-sm font-medium"
                                    style={
                                        step.done ? { color: 'var(--muted-foreground)' } : undefined
                                    }
                                >
                                    {step.title}
                                </h3>
                                {step.done && (
                                    <Pill tone="success">{tc('console.done_11a6767d')}</Pill>
                                )}
                            </div>
                            {
                                <p
                                    className="mt-0.5 text-xs"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {step.description}
                                </p>
                            }
                        </div>

                        {!step.done && step.href !== null && (
                            <Button asChild size="sm">
                                {/* The teammate step opens the workspace, on its own host. */}
                                {step.href.startsWith('http') &&
                                !step.href.startsWith(window.location.origin) ? (
                                    <a href={step.href}>{step.actionLabel}</a>
                                ) : (
                                    <Link href={step.href}>{step.actionLabel}</Link>
                                )}
                            </Button>
                        )}
                    </li>
                ))}
            </ol>
            {!compact && optional.length > 0 && (
                <div className="mt-5">
                    <Disclosure
                        title={tc('console.ux_optional_features')}
                        summary={tc('console.ux_setup_ready_help')}
                    >
                        <ul className="divide-y divide-[var(--border)]">
                            {optional.map((step) => (
                                <li
                                    key={step.key}
                                    data-step={step.key}
                                    data-done={step.done}
                                    className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="min-w-0 flex-1 basis-48">
                                        <h3 className="text-sm font-medium">{step.title}</h3>
                                        <p className="mt-1 text-xs text-[var(--muted-foreground)]">
                                            {step.description}
                                        </p>
                                    </div>
                                    {step.done ? (
                                        <Pill tone="success">{tc('console.done_11a6767d')}</Pill>
                                    ) : (
                                        step.href && (
                                            <Button asChild size="sm">
                                                {step.href.startsWith('http') &&
                                                !step.href.startsWith(window.location.origin) ? (
                                                    <a href={step.href}>{step.actionLabel}</a>
                                                ) : (
                                                    <Link href={step.href}>{step.actionLabel}</Link>
                                                )}
                                            </Button>
                                        )
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                </div>
            )}
        </section>
    );
}
