import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <>
            <PageHeader
                eyebrow={eyebrow}
                description={consoleFinalText(
                    'console._part1_things_worth_doing_roughly_in_this_order_each_on_b3e154c4',
                    { part1: total },
                )}
                actions={
                    <Button onClick={() => router.post(dismissHref)}>
                        {consoleT('console.don_t_show_this_again_497a0f94')}
                    </Button>
                }
            />

            <div className="card p-5 mt-6 mb-6">
                <div className="flex items-center gap-3">
                    <Progress
                        percent={percent}
                        label={consoleT('console.setup_progress_e9b4495a')}
                    />
                    <span
                        className="text-sm mono shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {consoleRich('console._part1_of_part2_934043a7', {
                            part1: completed,
                            part2: total,
                        })}
                    </span>
                </div>

                {isComplete ? (
                    <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.that_is_everything_on_the_list_from_here_the_console_is_d3be406f',
                            {
                                part1: ' ',
                                part2: (
                                    <Link
                                        href={auditHref}
                                        className="underline"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {consoleT('console.audit_log_83825da3')}
                                    </Link>
                                ),
                                part3: ' ',
                                part4: ' ',
                                part5: (
                                    <Link
                                        href={governanceHref}
                                        className="underline"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {consoleT('console.access_reviews_0437741a')}
                                    </Link>
                                ),
                                part6: ' ',
                            },
                        )}
                    </p>
                ) : (
                    nextTitle !== null && (
                        <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleRich('console.next_up_part1_part2_d64a6a14', {
                                part1: ' ',
                                part2: (
                                    <span style={{ color: 'var(--foreground)', fontWeight: 500 }}>
                                        {nextTitle}
                                    </span>
                                ),
                            })}
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
                                {step.done && (
                                    <Pill tone="success">{consoleT('console.done_11a6767d')}</Pill>
                                )}
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
                            <Link href={step.href}>
                                {step.done
                                    ? consoleText('console.review_aff0766a')
                                    : step.actionLabel}
                            </Link>
                        </Button>
                    </li>
                ))}
            </ol>

            <p className="mt-6 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {consoleRich(
                    'console.stuck_on_one_of_these_every_page_in_the_console_has_a_p_2aa4622e',
                    {
                        part1: ' ',
                        part2: (
                            <span className="inline-flex align-middle">
                                <Icon name="help" className="w-4 h-4" />
                            </span>
                        ),
                        part3: ' ',
                    },
                )}
            </p>
        </>
    );
}

GetStarted.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
