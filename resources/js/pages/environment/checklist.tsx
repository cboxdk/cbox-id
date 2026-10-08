import { Link } from '@inertiajs/react';
import { Button, Icon, Pill, Progress } from '@/ui';

/** `App\Platform\Onboarding\EnvironmentChecklist::toProps()` */
export interface ChecklistStep {
    key: string;
    title: string;
    description: string;
    actionLabel: string;
    href: string | null;
    done: boolean;
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
    const steps = compact
        ? checklist.steps.filter((step) => !step.done).slice(0, 3)
        : checklist.steps;

    return (
        <section className="card p-5" aria-labelledby="environment-checklist-title">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id="environment-checklist-title" className="font-semibold">
                    Get started
                </h2>
                <div className="flex flex-1 items-center gap-3 min-w-[10rem]">
                    <Progress percent={checklist.percent} label="Setup progress" />
                    <span
                        className="text-sm mono shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {checklist.completed} of {checklist.total}
                    </span>
                </div>
                {compact && (
                    <div className="flex items-center gap-2">
                        {href !== undefined && (
                            <Button asChild size="sm">
                                <Link href={href}>See all</Link>
                            </Button>
                        )}
                        {onDismiss !== undefined && (
                            <Button size="sm" variant="ghost" onClick={onDismiss}>
                                Hide
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {checklist.next !== null && (
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Next up:{' '}
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
                                {step.done && <Pill tone="success">Done</Pill>}
                            </div>
                            {!compact && (
                                <p
                                    className="mt-0.5 text-xs"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {step.description}
                                </p>
                            )}
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
        </section>
    );
}
