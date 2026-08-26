import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Help, Icon, PageHeader, Pill, Progress } from '@/ui';

interface Step {
    key: string;
    title: string;
    description: string;
    href: string;
    actionLabel: string;
    done: boolean;
    help: HelpContent;
}

type Props = PageProps<{
    eyebrow: string;
    steps: Step[];
    completed: number;
    total: number;
    percent: number;
    isComplete: boolean;
    nextTitle: string | null;
    auditHref: string;
    governanceHref: string;
    dismissHref: string;
}>;

export default function GetStarted({
    eyebrow,
    steps,
    completed,
    total,
    percent,
    isComplete,
    nextTitle,
    auditHref,
    governanceHref,
    dismissHref,
}: Props) {
    return (
        <>
            <PageHeader
                eyebrow={eyebrow}
                description={`${total} things worth doing, roughly in this order. Each one ticks itself off as soon as it is genuinely done — there is nothing to mark complete by hand.`}
                actions={
                    <Button onClick={() => router.post(dismissHref)}>Don't show this again</Button>
                }
            />

            <div className="card p-5 mt-6 mb-6">
                <div className="flex items-center gap-3">
                    <Progress percent={percent} label="Setup progress" />
                    <span
                        className="text-sm mono shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {completed} of {total}
                    </span>
                </div>

                {isComplete ? (
                    <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        That is everything on the list. From here the console is mostly somewhere
                        you come back to — the{' '}
                        <Link
                            href={auditHref}
                            className="underline"
                            style={{ color: 'var(--accent-strong)' }}
                        >
                            activity log
                        </Link>{' '}
                        and{' '}
                        <Link
                            href={governanceHref}
                            className="underline"
                            style={{ color: 'var(--accent-strong)' }}
                        >
                            access reviews
                        </Link>{' '}
                        are the two worth a standing habit.
                    </p>
                ) : (
                    nextTitle !== null && (
                        <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            Next up:{' '}
                            <span style={{ color: 'var(--foreground)', fontWeight: 500 }}>
                                {nextTitle}
                            </span>
                            .
                        </p>
                    )
                )}
            </div>

            <ol className="space-y-3">
                {steps.map((step, index) => (
                    <li
                        key={step.key}
                        className="card p-5 flex items-start gap-4"
                        style={step.done ? { background: 'var(--secondary)' } : undefined}
                    >
                        <span
                            className="grid place-items-center rounded-full shrink-0 mt-0.5"
                            style={{
                                width: '1.75rem',
                                height: '1.75rem',
                                ...(step.done
                                    ? {
                                          background: 'var(--success-soft)',
                                          color: 'var(--success-strong)',
                                      }
                                    : {
                                          border: '1px solid var(--border)',
                                          color: 'var(--muted-foreground)',
                                          fontFamily: 'var(--font-mono)',
                                          fontSize: '12px',
                                          fontWeight: 600,
                                      }),
                            }}
                            aria-hidden="true"
                        >
                            {step.done ? <Icon name="check" className="w-4 h-4" /> : index + 1}
                        </span>

                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-1">
                                <h2
                                    className="font-semibold"
                                    style={
                                        step.done ? { color: 'var(--muted-foreground)' } : undefined
                                    }
                                >
                                    {step.title}
                                </h2>
                                <Help help={step.help} />
                                {step.done && <Pill tone="success">Done</Pill>}
                            </div>
                            <p
                                className="mt-1 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {step.description}
                            </p>
                        </div>

                        <Button
                            asChild
                            size="sm"
                            variant={step.done ? undefined : 'primary'}
                            className="shrink-0 self-center"
                        >
                            <Link href={step.href}>{step.done ? 'Review' : step.actionLabel}</Link>
                        </Button>
                    </li>
                ))}
            </ol>

            <p className="mt-6 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Stuck on one of these? Every page in the console has a{' '}
                <span className="inline-flex align-middle">
                    <Icon name="help" className="w-4 h-4" />
                </span>{' '}
                beside its title with the short version, and a link to the full guide.
            </p>
        </>
    );
}

GetStarted.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
