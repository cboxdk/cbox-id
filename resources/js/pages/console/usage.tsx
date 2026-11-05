import { useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { EmptyState, PageHeader, Panel, Table, Td, TdMono, Th } from '@/ui';

interface Metric {
    key: string;
    label: string;
    total: number;
}

type Props = PageProps<{
    organization: string | null;
    environmentWide: boolean;
    metrics: Metric[];
    series: { day: string; label: string; value: number }[];
    window: { from: string; to: string };
    help: HelpContent;
}>;

export default function Usage({
    organization,
    environmentWide,
    metrics,
    series,
    window: period,
    help,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const scope = environmentWide
        ? tc('console.this_environment_42756031')
        : (organization ?? tc('console.your_organization_bb0bdf0a'));

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.activity_across_value0_last_30_days_this_is__8ff061d6', {
                    value0: scope,
                })}
            />

            {metrics.length === 0 ? (
                <div className="mt-6">
                    <EmptyState
                        icon="audit"
                        title={tc('console.no_activity_recorded_yet_2d3a6f4f')}
                        description={tc(
                            'console.usage_counters_fill_as_your_team_signs_in_in_a589d0b9',
                        )}
                    />
                </div>
            ) : (
                <>
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {metrics.slice(0, 8).map((metric) => (
                            <div key={metric.key} className="card p-5">
                                <div
                                    className="text-sm truncate"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {metric.label}
                                </div>
                                <p className="mt-2 text-3xl font-semibold tracking-tight mono">
                                    {metric.total.toLocaleString(locale)}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-4">
                        <Panel
                            title={tc('console.sign_ins_over_time_7418e68f')}
                            action={
                                <span className="text-xs mono" style={{ color: 'var(--faint)' }}>
                                    {period.from} – {period.to}
                                </span>
                            }
                        >
                            <SignInBars series={series} />
                        </Panel>
                    </div>

                    <div className="mt-4">
                        <Panel title={tc('console.all_metrics_ba2b7011')}>
                            <Table
                                caption={tc(
                                    'console.every_usage_counter_for_this_window_and_the__22633817',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{tc('console.metric_2d275a74')}</Th>
                                        <Th>{tc('console.key_99a52df3')}</Th>
                                        <Th className="text-right">
                                            {tc('console.total_c9b3c382')}
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {metrics.map((metric) => (
                                        <tr key={metric.key}>
                                            <Td>{metric.label}</Td>
                                            {/*
                                                The raw key beside the label: the person
                                                reading this may also be the one who has to
                                                go and find it in the meter.
                                            */}
                                            <TdMono>{metric.key}</TdMono>
                                            <Td className="text-right mono font-medium">
                                                {metric.total.toLocaleString(locale)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Panel>
                    </div>
                </>
            )}
        </>
    );
}

/**
 * Thirty days of sign-ins.
 *
 * A CHART IS AN IMAGE TO A SCREEN READER, so it carries one accessible name and the numbers
 * are ALSO available as text — the table below this panel is that text, which is why this
 * does not repeat itself into a hidden list. The bars carry a title each so a mouse can
 * read one day; the shape is what the eye is here for.
 */
function SignInBars({ series }: { series: Props['series'] }) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const max = series.reduce((highest, point) => Math.max(highest, point.value), 0);
    const total = series.reduce((sum, point) => sum + point.value, 0);

    return (
        <figure style={{ margin: 0 }}>
            {/*
                REAL TEXT, not an `aria-label` on a `role="img"` wrapper. The sentence is
                the same either way, but as a caption it is a thing a screen reader
                encounters in the reading order rather than a name it must be asked for —
                and it is available to anybody who wants the summary without counting bars.
            */}
            <figcaption className="sr-only">
                {tc('console.daily_sign_ins_over_the_last_9efbf559')}
                {series.length}
                {tc('console.days_7541abbc')}
                {total.toLocaleString(locale)}
                {tc('console.in_total_peaking_at_883c8b50')}
                {max.toLocaleString(locale)}
                {tc('console.in_a_day_5b32e772')}
            </figcaption>

            <div
                className="flex items-end gap-[3px]"
                style={{ height: '120px' }}
                aria-hidden="true"
            >
                {series.map((point) => (
                    <div
                        key={point.day}
                        className="flex-1 rounded-t"
                        title={`${point.label}: ${point.value.toLocaleString(locale)}`}
                        style={{
                            // A floor of 2%, so a day with no sign-ins is still a visible
                            // baseline rather than a gap the eye reads as missing data.
                            height: `${max > 0 ? Math.max(2, Math.round((point.value / max) * 100)) : 2}%`,
                            background: point.value > 0 ? 'var(--accent)' : 'var(--border)',
                            minHeight: '2px',
                        }}
                    />
                ))}
            </div>
        </figure>
    );
}

Usage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
