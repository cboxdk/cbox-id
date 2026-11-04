import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                eyebrow={eyebrow}
                description={tc('console.value0_things_worth_doing_roughly_in_this_or_777bae3f', {
                    value0: total,
                })}
                actions={
                    <Button onClick={() => router.post(dismissHref)}>
                        {tc('console.don_t_show_this_again_497a0f94')}
                    </Button>
                }
            />

            <div className="card p-5 mt-6 mb-6">
                <div className="flex items-center gap-3">
                    <Progress percent={percent} label={tc('console.setup_progress_e9b4495a')} />
                    <span
                        className="text-sm mono shrink-0"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {completed}
                        {tc('console.of_a4282e4b')}
                        {total}
                    </span>
                </div>

                {isComplete ? (
                    <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.that_is_everything_on_the_list_from_here_the_1a49c096')}{' '}
                        <Link
                            href={auditHref}
                            className="underline"
                            style={{ color: 'var(--accent-strong)' }}
                        >
                            {tc('console.audit_log_83825da3')}
                        </Link>{' '}
                        {tc('console.and_6201111b')}{' '}
                        <Link
                            href={governanceHref}
                            className="underline"
                            style={{ color: 'var(--accent-strong)' }}
                        >
                            {tc('console.access_reviews_0437741a')}
                        </Link>{' '}
                        {tc('console.are_the_two_worth_a_standing_habit_d02fb30f')}
                    </p>
                ) : (
                    nextTitle !== null && (
                        <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.next_up_1eab46b7')}{' '}
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
                                {step.done && (
                                    <Pill tone="success">{tc('console.done_11a6767d')}</Pill>
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
                                {step.done ? tc('console.review_aff0766a') : step.actionLabel}
                            </Link>
                        </Button>
                    </li>
                ))}
            </ol>

            <p className="mt-6 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.stuck_on_one_of_these_every_page_in_the_cons_32f1f4cd')}{' '}
                <span className="inline-flex align-middle">
                    <Icon name="help" className="w-4 h-4" />
                </span>{' '}
                {tc('console.beside_its_title_with_the_short_version_and__b62de92b')}
            </p>
        </>
    );
}

GetStarted.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
