import { useTranslator } from '@/i18n';
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
    const { t: tc, locale } = useTranslator();
    const plural = (count: number, one: string, many: string) =>
        `${count.toLocaleString(locale)} ${count === 1 ? one : many}`;

    const scope = wholeEnvironment
        ? tc('console.in_this_environment_d3511377')
        : tc('console.for_this_organization_a02ed7fb');

    return (
        <div className="space-y-8">
            <PageHeader
                help={help}
                description={tc('console.every_kind_of_integration_this_platform_has__4b2a3ee2')}
                actions={
                    <Button asChild className="shrink-0">
                        <Link href={connectionsHref}>
                            <Icon name="connections" className="w-4 h-4" />
                            {tc('console.all_connections_72038b84')}
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
                                {plural(
                                    totals.failing,
                                    tc('console.connection_is_bdb336f1'),
                                    tc('console.connections_are_141d19ac'),
                                )}
                                {tc('console.failing_f533a6e9')}
                            </strong>{' '}
                            {scope}
                            {tc('console.their_own_records_say_the_last_attempt_did_n_82c772c8')}
                        </p>
                        <Button asChild size="sm" variant="primary">
                            <Link href={failingHref}>{tc('console.review_failing_f17758a8')}</Link>
                        </Button>
                    </div>
                </div>
            ) : (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {totals.connections === 0
                        ? tc('console.nothing_is_connected_value0_yet_pick_what_yo_fa315245', {
                              value0: scope,
                          })
                        : tc('console.value0_value1_none_failing_a5d264cb', {
                              value0: plural(
                                  totals.connections,
                                  tc('console.connection_b38d9d16'),
                                  tc('console.connections_1e5fac86'),
                              ),
                              value1: scope,
                          })}{' '}
                    {totals.connections > 0 && (
                        <Link href={connectionsHref} className="underline underline-offset-2">
                            {tc('console.see_them_all_74900e3c')}
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

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
                        {type.failing.toLocaleString(locale)}
                        {tc('console.failing_f533a6e9')}
                    </Pill>
                ) : type.count > 0 ? (
                    <Pill tone="success" className="shrink-0 tabular-nums">
                        {type.count.toLocaleString(locale)}
                        {tc('console.live_846b33ce')}
                    </Pill>
                ) : (
                    <Pill className="shrink-0">{tc('console.not_set_up_94b768af')}</Pill>
                )}
            </div>

            <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {type.summary}
            </p>

            {type.options.length > 0 && (
                <div className="relative z-10 mt-3">
                    <p className="sr-only">
                        {tc('console.set_up_f0ed49ff')}
                        {type.name}
                        {tc('console.with_6a413b57')}
                    </p>
                    <ul className="flex flex-wrap gap-1.5">
                        {type.options.map((option) => (
                            <li key={option.key}>
                                <Link href={option.href} className="cbx-connector-option">
                                    <ProviderMark provider={option.key} size={14} />
                                    <span className="sr-only">{tc('console.set_up_f0ed49ff')}</span>
                                    {option.label}
                                </Link>
                            </li>
                        ))}
                        {type.moreOptions > 0 && type.setupHref !== null && (
                            <li>
                                <Link href={type.setupHref} className="cbx-connector-option">
                                    +{type.moreOptions}
                                    {tc('console.more_226ba18b')}
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
                                {tc('console.set_up_4da10f1f')}
                                <span className="sr-only"> {type.name}</span>
                            </span>
                        </Link>
                    </Button>
                )}
                {type.count > 0 && (
                    <Button asChild size="sm" variant="primary">
                        <Link href={type.manageHref}>
                            <span>
                                {tc('console.manage_5a234448')}
                                <span className="sr-only"> {type.name}</span>
                                {` (${type.count.toLocaleString(locale)})`}
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
                        {tc('console.see_what_is_failing_1e6030de')}
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
                        {tc('console.guide_8dd65d09')}
                        <Icon name="external" className="w-3 h-3" aria-hidden="true" />
                        <span className="sr-only">{tc('console.opens_in_a_new_tab_1d183356')}</span>
                    </a>
                )}
            </div>
        </li>
    );
}

Catalog.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
