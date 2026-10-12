import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Icon, PageHeader, Pill, ProviderMark } from '@/ui';

interface ConnectorOption {
    key: string;
    label: string;
    /** The setup form, with this provider / HR system / destination already chosen. */
    href: string;
}

interface ConnectorType {
    key: string;
    name: string;
    summary: string;
    group: string;
    direction: string;
    /** Live connections of this type, in the scope the page is about. */
    count: number;
    active: number;
    failing: number;
    /** Null where this console cannot start one (the form is not served here). */
    setupHref: string | null;
    manageHref: string;
    /** The unified list, narrowed to this type's failing connections; null when none are. */
    failingHref: string | null;
    docsHref: string | null;
    options: ConnectorOption[];
    moreOptions: number;
}

type Props = PageProps<{
    help: HelpContent;
    groups: string[];
    types: ConnectorType[];
    totals: { connections: number; failing: number };
    connectionsHref: string;
    failingHref: string | null;
    wholeEnvironment: boolean;
}>;

function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString()} ${count === 1 ? one : many}`;
}

/**
 * Connectors › Catalog — every kind of integration, and the next step for each.
 *
 * EVERY CARD LEADS SOMEWHERE. The card's name is the card's link (stretched over it, so the
 * whole card is a target), and it goes where the next step is: the list when there are
 * connections to manage, the setup form when there are none. The buttons below it say both
 * out loud, and the shortcuts open the setup form with that provider already chosen. The
 * pages they lead to do the work — this page writes nothing.
 */
export default function Catalog({
    help,
    groups,
    types,
    totals,
    connectionsHref,
    failingHref,
    wholeEnvironment,
}: Props) {
    const scope = wholeEnvironment ? 'in this environment' : 'for this organization';

    return (
        <div className="space-y-8">
            <PageHeader
                help={help}
                description="Every kind of integration this platform has. Set one up from its card, or manage the ones you have — each card leads to the page where that integration lives."
                actions={
                    <Button asChild className="shrink-0">
                        <Link href={connectionsHref}>
                            <Icon name="connections" className="w-4 h-4" />
                            All connections
                        </Link>
                    </Button>
                }
            />

            {failingHref !== null ? (
                <div
                    className="card p-4"
                    style={{
                        borderColor: 'color-mix(in oklch, var(--warning) 45%, transparent)',
                        background: 'var(--warning-soft)',
                    }}
                >
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <Icon
                            name="warning"
                            className="w-4 h-4 shrink-0"
                            style={{ color: 'var(--warning-strong)' }}
                        />
                        <p className="min-w-0 flex-1 text-sm" style={{ minWidth: '12rem' }}>
                            <strong>
                                {plural(totals.failing, 'connection is', 'connections are')} failing
                            </strong>{' '}
                            {scope}. Their own records say the last attempt did not get through.
                        </p>
                        <Button asChild size="sm" variant="primary">
                            <Link href={failingHref}>Review failing</Link>
                        </Button>
                    </div>
                </div>
            ) : (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {totals.connections === 0
                        ? `Nothing is connected ${scope} yet. Pick what you need below.`
                        : `${plural(totals.connections, 'connection', 'connections')} ${scope}, none failing.`}{' '}
                    {totals.connections > 0 && (
                        <Link href={connectionsHref} className="underline underline-offset-2">
                            See them all
                        </Link>
                    )}
                </p>
            )}

            {groups.map((group) => {
                const members = types.filter((type) => type.group === group);

                if (members.length === 0) {
                    return null;
                }

                return (
                    <section key={group} aria-labelledby={`group-${group}`} className="space-y-3">
                        <h2
                            id={`group-${group}`}
                            className="text-xs font-semibold uppercase tracking-wide"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {group}
                        </h2>
                        <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {members.map((type) => (
                                <ConnectorCard key={type.key} type={type} />
                            ))}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}

function ConnectorCard({ type }: { type: ConnectorType }) {
    // Where the card itself goes: the list once there is something in it, the setup form
    // before — the next step either way.
    const primary = type.count > 0 || type.setupHref === null ? type.manageHref : type.setupHref;

    return (
        <li className="card cbx-connector-card relative flex flex-col p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold">
                        <Link
                            href={primary}
                            className="cbx-connector-card-link after:absolute after:inset-0 after:rounded-[inherit] after:content-['']"
                        >
                            {type.name}
                        </Link>
                    </h3>
                    <p
                        className="mt-0.5 text-xs font-medium uppercase tracking-wide"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {type.direction}
                    </p>
                </div>

                {type.failing > 0 ? (
                    <Pill tone="warning" className="shrink-0 tabular-nums">
                        {type.failing.toLocaleString()} failing
                    </Pill>
                ) : type.count > 0 ? (
                    <Pill tone="success" className="shrink-0 tabular-nums">
                        {type.count.toLocaleString()} live
                    </Pill>
                ) : (
                    <Pill className="shrink-0">Not set up</Pill>
                )}
            </div>

            <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {type.summary}
            </p>

            {type.options.length > 0 && (
                <div className="relative z-10 mt-3">
                    <p className="sr-only">Set up {type.name} with</p>
                    <ul className="flex flex-wrap gap-1.5">
                        {type.options.map((option) => (
                            <li key={option.key}>
                                <Link href={option.href} className="cbx-connector-option">
                                    <ProviderMark provider={option.key} size={14} />
                                    <span className="sr-only">Set up </span>
                                    {option.label}
                                </Link>
                            </li>
                        ))}
                        {type.moreOptions > 0 && type.setupHref !== null && (
                            <li>
                                <Link href={type.setupHref} className="cbx-connector-option">
                                    +{type.moreOptions} more
                                </Link>
                            </li>
                        )}
                    </ul>
                </div>
            )}

            <div className="relative z-10 mt-auto flex flex-wrap items-center gap-2 pt-4">
                {type.setupHref !== null && (
                    <Button asChild size="sm" variant={type.count === 0 ? 'primary' : undefined}>
                        <Link href={type.setupHref}>
                            <Icon name="plus" className="w-3.5 h-3.5" />
                            <span>
                                Set up<span className="sr-only"> {type.name}</span>
                            </span>
                        </Link>
                    </Button>
                )}
                {type.count > 0 && (
                    <Button asChild size="sm" variant="primary">
                        <Link href={type.manageHref}>
                            <span>
                                Manage<span className="sr-only"> {type.name}</span>
                                {` (${type.count.toLocaleString()})`}
                            </span>
                        </Link>
                    </Button>
                )}
                {type.failingHref !== null && (
                    <Link
                        href={type.failingHref}
                        className="text-xs font-medium underline underline-offset-2"
                        style={{ color: 'var(--warning-strong)' }}
                    >
                        See what is failing
                    </Link>
                )}
                {type.docsHref !== null && (
                    <a
                        href={type.docsHref}
                        target="_blank"
                        rel="noreferrer"
                        className="ml-auto inline-flex items-center gap-1 text-xs underline-offset-2 hover:underline"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        Guide
                        <Icon name="external" className="w-3 h-3" aria-hidden="true" />
                        <span className="sr-only">(opens in a new tab)</span>
                    </a>
                )}
            </div>
        </li>
    );
}

Catalog.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
