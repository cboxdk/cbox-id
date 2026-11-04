import { useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { EmptyState, PageHeader } from '@/ui';

interface Tile {
    key: string;
    label: string;
    total: number;
    bars: { day: string; count: number }[];
    /** Floored at 1, so a window of zeroes still draws a baseline rather than dividing by 0. */
    max: number;
}

type Props = PageProps<{
    window: number;
    tiles: Tile[];
    mfaRate: number;
    /** True when no analytics data source is configured or reachable. */
    unavailable: boolean;
    help: HelpContent;
}>;

export default function SignInActivity({ window: days, tiles, mfaRate, unavailable, help }: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={tc('console.authentication_activity_over_the_last_value0_2eba9be5', {
                    value0: days,
                })}
            />

            {unavailable ? (
                <EmptyState
                    icon="chart"
                    title={tc('console.analytics_isn_t_available_yet_5a8a4968')}
                    description={tc(
                        'console.no_analytics_data_source_is_configured_or_re_1db9abb5',
                    )}
                />
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {tiles.map((tile) => (
                            <div key={tile.key} className="card p-4">
                                <p
                                    className="text-xs font-medium uppercase tracking-wide"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {tile.label}
                                </p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums mono">
                                    {tile.total.toLocaleString(locale)}
                                </p>

                                <Sparkline tile={tile} days={days} />
                            </div>
                        ))}
                    </div>

                    {/*
                        "Active organizations" is gone: a count of the OTHER tenants sharing
                        this environment has no organization-scoped meaning, and reporting it
                        to one of them is a disclosure rather than a metric. It belongs on the
                        environment plane, which has its own usage page.
                    */}
                    <div className="grid grid-cols-1 gap-4 sm:max-w-md">
                        <div className="card p-4">
                            <p
                                className="text-xs font-medium uppercase tracking-wide"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {tc('console.mfa_rate_5faf1bb4')}
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums mono">
                                {mfaRate}%
                            </p>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

/**
 * One metric's shape over the window.
 *
 * A CAPTION RATHER THAN A LABELLED IMAGE. The bars are decorative — the number above them
 * is the fact, and the caption says what the shape adds — so a screen reader meets real text
 * in the reading order instead of being handed an `aria-label` it has to ask for.
 */
function Sparkline({ tile, days }: { tile: Tile; days: number }) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <figure style={{ margin: 0 }}>
            <figcaption className="sr-only">
                {tile.label}
                {tc('console.over_the_last_2c380ffd')}
                {days}
                {tc('console.days_peaking_at_837f3f85')}
                {tile.max.toLocaleString(locale)}
                {tc('console.in_a_day_5b32e772')}
            </figcaption>

            <div className="mt-3 flex h-10 items-end gap-px" aria-hidden="true">
                {tile.bars.map((bar) => (
                    <span
                        key={bar.day}
                        className="flex-1 rounded-sm"
                        title={`${bar.day}: ${bar.count}`}
                        style={{
                            // A floor of 2%, so a quiet day is a visible baseline rather than
                            // a gap the eye reads as missing data.
                            height: `${Math.max(2, Math.round((bar.count / tile.max) * 100))}%`,
                            background: 'var(--accent)',
                        }}
                    />
                ))}
            </div>
        </figure>
    );
}

SignInActivity.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
