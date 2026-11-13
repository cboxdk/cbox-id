import { useTranslator } from '@/i18n';
import type { MessageKey } from '@/i18n/keys';
import { Link, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import { relativeTime } from '@/lib/time';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
} from '@/ui';
import { type AppFace, type AppKindKey, AppMark, KIND_ICONS } from './AppMark';

type AppGroup = 'first-party' | 'environment' | 'organizations';

interface AppRow {
    id: string;
    name: string;
    clientId: string;
    firstParty: boolean;
    platformOwned: boolean;
    kindLabel: string;
    owner: string | null;
    href: string;
    group: AppGroup;
    kind: AppKindKey;
    /** Where it lives: the host of its first redirect URI, or null for an app no browser returns to. */
    host: string | null;
    signsIn: boolean;
    redirectCount: number;
    backchannelLogout: boolean;
    lastUsedAt: string | null;
    face: AppFace | null;
    /** Switched off (needs a secret, holds none), never used, idle for 30+ days, or in use. */
    status: AppStatus;
    /** The product app this one nests under on this page, or null for a product of its own. */
    parentId: string | null;
    /** The product it belongs to — said on a flat (searched or kind-narrowed) row. */
    product: string | null;
    /** The section it is drawn in: its product's owner group. */
    section: AppGroup;
}

type AppStatus = 'active' | 'stale' | 'never-used' | 'disabled';

/** A product: its head app and the helper clients behind it. */
interface Product {
    head: AppRow;
    helpers: AppRow[];
}

type Props = PageProps<{
    help: HelpContent;
    clients: AppRow[];
    /** Rows in the list — every one, on one page. */
    total: number;
    /** Helpers nest under their product: nothing narrows the list. */
    nested: boolean;
    platformApps: AppRow[];
    search: string;
    /** The owner group in view, or null for every group. */
    group: AppGroup | null;
    /** Rows per group under the other narrowings; null where the list is one organization's. */
    groupCounts: Record<AppGroup, number> | null;
    kind: AppKindKey | null;
    kindCounts: Record<AppKindKey, number>;
    /** No organization is chosen: every app in the environment is in view. */
    showsEveryOrganization: boolean;
    mayAdminister: boolean;
    createHref: string;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

const GROUPS: AppGroup[] = ['first-party', 'environment', 'organizations'];
const KINDS: AppKindKey[] = ['web', 'spa', 'cli', 'service', 'agent', 'other'];

const GROUP_TITLE: Record<AppGroup, MessageKey> = {
    'first-party': 'console.apps_group_first_party',
    environment: 'console.apps_group_environment',
    organizations: 'console.apps_group_organizations',
};

const GROUP_LEAD: Record<AppGroup, MessageKey> = {
    'first-party': 'console.apps_group_first_party_lead',
    environment: 'console.apps_group_environment_lead',
    organizations: 'console.apps_group_organizations_lead',
};

const KIND_LABEL: Record<AppKindKey, MessageKey> = {
    web: 'console.apps_kind_web',
    spa: 'console.apps_kind_spa',
    cli: 'console.apps_kind_cli',
    service: 'console.apps_kind_service',
    agent: 'console.apps_kind_agent',
    other: 'console.apps_kind_other',
};

/** This page's URL with one narrowing set or dropped — and back to page one. */
function narrowed(parameter: string, value: string | null): string {
    const query = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);

    if (value === null) {
        query.delete(parameter);
    } else {
        query.set(parameter, value);
    }

    query.delete('page');

    const path = typeof window === 'undefined' ? '' : window.location.pathname;
    const rest = query.toString();

    return rest === '' ? path : `${path}?${rest}`;
}

/**
 * CONSOLE › APPS — every app registered here, in the groups a person looks for one in.
 *
 * WHO OWNS IT is the first cut (tabs, and section headings inside "All"): the environment's
 * own first-party apps, its other apps, and the organizations' own. WHAT KIND it is is the
 * second (chips). Both are in the URL, so a narrowed list can be linked and reloaded, and
 * both say how many rows choosing them shows.
 *
 * Each row is the link to the app: its face from the catalog, its name, where it lives, what
 * kind it is, who owns it and when it last asked for a token.
 */
export default function Clients({
    help,
    clients,
    total: rowCount,
    nested,
    platformApps,
    search,
    group,
    groupCounts,
    kind,
    kindCounts,
    showsEveryOrganization,
    mayAdminister,
    createHref,
    organizationFilter,
}: Props) {
    const { t, rich } = useTranslator();
    const [term, setTerm] = useState(search);

    // Debounced, and back to page one: a filter applied while on page four otherwise asks
    // for a page of a list that no longer has one.
    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    const newApp = mayAdminister ? (
        <Button asChild variant="primary" className="shrink-0">
            <Link href={createHref}>
                {rich('console._part1_new_app_f8e9ca57', {
                    part1: <Icon name="plus" className="w-4 h-4" />,
                })}
            </Link>
        </Button>
    ) : undefined;

    const total = groupCounts === null ? null : GROUPS.reduce((n, g) => n + groupCounts[g], 0);
    const products = useMemo(() => intoProducts(clients, nested), [clients, nested]);
    const narrowedAtAll = search !== '' || kind !== null || group !== null;

    return (
        <>
            <PageHeader
                help={help}
                description={t(
                    'console.every_app_that_signs_people_in_through_cbox_id_or_calls_bf1c3a89',
                )}
                actions={newApp}
            />

            {groupCounts !== null && (
                <nav className="cbx-tabs mt-6" aria-label={t('console.apps_groups_label')}>
                    <GroupTab
                        href={narrowed('group', null)}
                        current={group === null}
                        label={t('console.apps_group_all')}
                        count={total}
                    />
                    {GROUPS.map((each) => (
                        <GroupTab
                            key={each}
                            href={narrowed('group', each)}
                            current={group === each}
                            label={t(GROUP_TITLE[each])}
                            count={groupCounts[each]}
                        />
                    ))}
                </nav>
            )}

            <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <Input
                    type="search"
                    className="md:max-w-sm"
                    placeholder={t('console.apps_search_placeholder')}
                    aria-label={t('console.search_apps_a10a36fa')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
            </div>

            <nav className="idc-chips mt-3" aria-label={t('console.apps_kinds_label')}>
                <Link
                    href={narrowed('type', null)}
                    className="idc-chip"
                    aria-current={kind === null ? 'true' : undefined}
                    preserveScroll
                >
                    {t('console.apps_kind_any')}
                </Link>
                {KINDS.filter((each) => kindCounts[each] > 0 || kind === each).map((each) => {
                    const KindIcon = KIND_ICONS[each];

                    return (
                        <Link
                            key={each}
                            href={narrowed('type', kind === each ? null : each)}
                            className="idc-chip"
                            aria-current={kind === each ? 'true' : undefined}
                            preserveScroll
                        >
                            <KindIcon className="h-3.5 w-3.5" strokeWidth={1.7} aria-hidden />
                            {t(KIND_LABEL[each])}
                            <span className="idc-chip-count">{kindCounts[each]}</span>
                        </Link>
                    );
                })}
            </nav>

            {/*
                SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                change, so the count is the only thing that can report the filter
                narrowed to nothing.
            */}
            <output className="sr-only">
                {rich('console.search_results_found', { count: rowCount })}
            </output>

            <div className="mt-4">
                {clients.length === 0 ? (
                    <div className="idc-rows">
                        {narrowedAtAll ? (
                            <EmptyState
                                icon="clients"
                                title={t('console.no_matching_apps_798636bc')}
                                description={
                                    search !== ''
                                        ? t(
                                              'console.no_app_matches_part1_try_a_different_name_or_client_id_e5aa7b40',
                                              { part1: search },
                                          )
                                        : t('console.apps_no_match_filters')
                                }
                                actions={
                                    <Button asChild>
                                        <Link href={window.location.pathname}>
                                            {t('console.apps_clear_filters')}
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon="clients"
                                equivalent="apps.create"
                                actions={newApp}
                                title={t('console.no_apps_of_your_own_yet_3fdb87bf')}
                                help={help}
                                description={
                                    t(
                                        'console.an_app_registered_here_can_sign_your_people_in_and_read_f35bdb6c',
                                    ) +
                                    (!showsEveryOrganization && platformApps.length > 0
                                        ? t(
                                              'console._the_platform_apps_below_belong_to_this_environment_rat_9f21136f',
                                          )
                                        : '')
                                }
                                steps={[
                                    t(
                                        'console.register_the_app_one_per_app_per_environment_bc8f1427',
                                    ),
                                    t(
                                        'console.copy_its_client_id_and_secret_into_the_app_s_configurat_1948de40',
                                    ),
                                    t(
                                        'console.add_the_exact_url_cbox_id_may_send_people_back_to_after_dfcd0f5e',
                                    ),
                                    t(
                                        'console.have_the_app_declare_the_roles_it_understands_so_you_ca_9074afe0',
                                    ),
                                ]}
                            />
                        )}
                    </div>
                ) : (
                    <div className="idc-rows">
                        {products.map((product, index) => {
                            // A heading where the section changes, so "All" reads as the
                            // owner groups in turn.
                            const heading =
                                showsEveryOrganization &&
                                group === null &&
                                (index === 0 ||
                                    products[index - 1]?.head.section !== product.head.section);

                            return (
                                <Fragment key={product.head.id}>
                                    {heading && (
                                        <div className="idc-rows-group">
                                            <h2>{t(GROUP_TITLE[product.head.section])}</h2>
                                            <p>{t(GROUP_LEAD[product.head.section])}</p>
                                        </div>
                                    )}
                                    <ProductRows
                                        product={product}
                                        showOwner={showsEveryOrganization}
                                    />
                                </Fragment>
                            );
                        })}
                    </div>
                )}
            </div>

            {/*
                Apps the ENVIRONMENT owns rather than the organization being administered:
                not registered here, but a first-party one can skip this organization's
                consent screen — so the page shows those integrations rather than
                looking like it lost an app.
            */}
            {!showsEveryOrganization && platformApps.length > 0 && (
                <section className="mt-8">
                    <div className="idc-rows">
                        <div className="idc-rows-group">
                            <h2>{t('console.apps_group_first_party')}</h2>
                            <p>
                                {t(
                                    'console._owned_by_this_environment_not_by_this_organization_fir_33b76fa6',
                                )}
                            </p>
                        </div>
                        {platformApps.map((client) => (
                            <AppLink key={client.id} app={client} showOwner={false} />
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}

function GroupTab({
    href,
    current,
    label,
    count,
}: {
    href: string;
    current: boolean;
    label: string;
    count: number | null;
}) {
    return (
        <Link
            href={href}
            className="idc-tab"
            data-state={current ? 'active' : 'inactive'}
            aria-current={current ? 'page' : undefined}
            preserveScroll
        >
            {label}
            {count !== null && <span className="idc-tab-count">{count}</span>}
        </Link>
    );
}

/**
 * The rows as products: each head app followed by the helpers that nest under it. A flat
 * list (searched, or narrowed to a kind) is every row its own product.
 */
function intoProducts(rows: AppRow[], nested: boolean): Product[] {
    const products: Product[] = [];
    const byHead = new Map<string, Product>();

    for (const row of rows) {
        const head = nested && row.parentId !== null ? byHead.get(row.parentId) : undefined;

        if (head !== undefined) {
            head.helpers.push(row);
            continue;
        }

        const product = { head: row, helpers: [] };
        products.push(product);
        byHead.set(row.id, product);
    }

    return products;
}

/** A helper's name without its product's: "Cbox Billing shared properties" → "Shared properties". */
function shortName(helper: string, head: string): string {
    if (!helper.toLowerCase().startsWith(`${head.toLowerCase()} `)) {
        return helper;
    }

    const rest = helper.slice(head.length).trim();

    return rest.charAt(0).toUpperCase() + rest.slice(1);
}

/**
 * A product: its head app as a row, and — when there are any — the helper clients behind
 * it folded into one line ("+2 · Shared properties, CLI") that opens to their rows.
 */
function ProductRows({ product, showOwner }: { product: Product; showOwner: boolean }) {
    const { t } = useTranslator();
    const { head, helpers } = product;

    if (helpers.length === 0) {
        return <AppLink app={head} showOwner={showOwner} />;
    }

    const names = helpers.map((helper) => shortName(helper.name, head.name));
    const anyOff = helpers.some((helper) => helper.status === 'disabled');

    return (
        <div className="idc-product">
            <AppLink app={head} showOwner={showOwner} />
            <details className="idc-product-more">
                <summary>
                    <ChevronRight className="idc-product-caret h-3.5 w-3.5" aria-hidden />
                    <span className="idc-product-count">
                        {t('console.apps_product_more', { count: helpers.length })}
                    </span>
                    <span className="idc-product-names">{names.join(', ')}</span>
                    {anyOff && <Badge tone="danger">{t('console.apps_status_disabled')}</Badge>}
                </summary>
                <div className="idc-product-helpers">
                    {helpers.map((helper) => (
                        <AppLink
                            key={helper.id}
                            app={helper}
                            showOwner={showOwner}
                            label={shortName(helper.name, head.name)}
                        />
                    ))}
                </div>
            </details>
        </div>
    );
}

/** One app: the whole row is the link to it. */
function AppLink({
    app,
    showOwner,
    label,
}: {
    app: AppRow;
    showOwner: boolean;
    /** The name to draw, when shorter than the app's own (a helper under its product). */
    label?: string;
}) {
    const { t, locale } = useTranslator();
    const off = app.status === 'disabled';

    return (
        <Link
            href={app.href}
            className="idc-row"
            data-status={app.status}
            title={`${app.name} · ${app.clientId}`}
        >
            <AppMark name={app.name} kind={app.kind} face={app.face} />

            <div className="idc-row-main">
                <span className="idc-row-title">{label ?? app.name}</span>
                <span className="idc-row-sub">
                    {app.host !== null ? (
                        <span className="mono">{app.host}</span>
                    ) : (
                        <span>{app.kindLabel}</span>
                    )}
                    {app.product !== null && app.parentId === null && (
                        <span>{t('console.apps_part_of', { product: app.product })}</span>
                    )}
                </span>
            </div>

            <div className="idc-row-meta">
                {app.host !== null && <Badge>{app.kindLabel}</Badge>}
                {app.firstParty && app.section !== 'first-party' && (
                    <Badge tone="info">{t('console.first_party_ee374836')}</Badge>
                )}
                {showOwner && app.owner !== null && app.group === 'organizations' && (
                    <Badge>
                        <Icon name="building" className="w-3 h-3" />
                        {app.owner}
                    </Badge>
                )}
                {app.signsIn && app.redirectCount === 0 && (
                    <Badge tone="warn">{t('console.apps_no_redirect')}</Badge>
                )}
                {off && (
                    <span title={t('console.apps_status_disabled_hint')}>
                        <Badge tone="danger">{t('console.apps_status_disabled')}</Badge>
                    </span>
                )}
                {app.status === 'stale' && (
                    <Badge tone="warn">{t('console.apps_status_stale')}</Badge>
                )}
                <span className="idc-row-when">
                    {app.lastUsedAt === null
                        ? t('console.apps_never_used')
                        : t('console.apps_last_used', {
                              when: relativeTime(app.lastUsedAt, locale),
                          })}
                </span>
            </div>

            <ChevronRight className="idc-row-chevron h-4 w-4" aria-hidden />
        </Link>
    );
}

Clients.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
