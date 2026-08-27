import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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
}>;

export default function SignInActivity({ window: days, tiles, mfaRate, unavailable }: Props) {
    return (
        <div className="space-y-6">
            <PageHeader
                description={`Authentication activity over the last ${days} days, from the platform's event stream.`}
            />

            {unavailable ? (
                <EmptyState
                    icon="chart"
                    title="Analytics isn't available yet"
                    description="No analytics data source is configured or reachable. Once the platform is recording usage — or a ClickHouse DSN is set — activity will appear here."
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
                                    {tile.total.toLocaleString()}
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
                                MFA rate
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
    return (
        <figure style={{ margin: 0 }}>
            <figcaption className="sr-only">
                {tile.label} over the last {days} days, peaking at {tile.max.toLocaleString()} in a
                day.
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
