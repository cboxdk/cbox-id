import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    Headset,
    KeyRound,
    Lock,
    Minus,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import type {
    HelpContent,
    OrganizationFilter,
    PageProps,
    Pagination as PaginationState,
} from '@/types';
import {
    AccessModelGuide,
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Panel,
} from '@/ui';

interface RoleRow {
    id: string;
    name: string;
    description: string | null;
    /** Declared by an application, which is its source of truth — read-only here. */
    declaredByApp: boolean;
    /** The app whose tokens it is stamped into, or null for every app. */
    app: string | null;
    environmentWide: boolean;
    /** An admin & support role: never offered to an organization's administrators. */
    staffOnly: boolean;
    /**
     * The organization that owns it — set only when every organization is in view, where
     * two organizations' "Editor" would otherwise be two identical rows.
     */
    organization: string | null;
    permissions: string[];
    permissionCount: number;
    holderCount: number;
    /** Whether this administrator may change it. */
    mayCompose: boolean;
    href: string;
}

type Source = 'custom' | 'app';

type Props = PageProps<{
    help: HelpContent;
    roles: RoleRow[];
    pagination: PaginationState;
    search: string;
    source: Source | null;
    sourceCounts: Record<Source, number>;
    mayAdminister: boolean;
    sample: { role: string; permissions: string[] } | null;
    createHref: string;
    builtIn: {
        roles: { value: string; label: string }[];
        abilities: { label: string; holders: string[] }[];
    };
    where: {
        /** Where a person's built-in role and app/custom roles in an organization are set. */
        membersHref: string | null;
        /** The environment's Admins & support page; null outside the environment console. */
        staffHref: string | null;
        staffCount: number | null;
    };
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

/** This page's URL with `?source=` set or dropped, back on page one. */
function withSource(value: Source | null): string {
    const query = new URLSearchParams(window.location.search);

    if (value === null) {
        query.delete('source');
    } else {
        query.set('source', value);
    }

    query.delete('page');
    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}

/**
 * CONSOLE › ROLES — the three kinds of role, told apart, and where each is given.
 *
 * It was one list with a paragraph above it, and the paragraph was the only place that said
 * a member also holds a built-in role, or that there is a second kind granted across the
 * whole environment. So the page now starts with WHERE: a person's role in an organization
 * is set on its Members page, an admin & support role on Admins & support. Then the
 * built-in roles as a table of what each may do. Then the app and custom roles, custom first
 * and each app's declared roles together, each row a link to the role.
 */
export default function RolesIndex({
    help,
    roles,
    pagination,
    search,
    source,
    sourceCounts,
    mayAdminister,
    sample,
    createHref,
    builtIn,
    where,
    organizationFilter,
}: Props) {
    const { t, rich } = useTranslator();
    const [term, setTerm] = useState(search);

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

    const newRole = mayAdminister ? (
        <Button asChild variant="primary" className="shrink-0">
            <Link href={createHref}>
                {rich('console._part1_new_role_db15d053', {
                    part1: <Icon name="plus" className="w-4 h-4" />,
                })}
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <PageHeader
                help={help}
                description={t(
                    'console.what_people_can_do_inside_your_apps_you_assign_roles_he_c31f1a20',
                )}
                actions={newRole}
            />

            <section className="idc-where-grid mt-6" aria-label={t('console.roles_where_label')}>
                <div className="idc-where">
                    <h2>
                        <Users className="h-4 w-4" strokeWidth={1.7} aria-hidden />
                        {t('console.roles_where_org_title')}
                    </h2>
                    <p>{t('console.roles_where_org_body')}</p>
                    {where.membersHref !== null && (
                        <Link href={where.membersHref}>{t('console.roles_where_org_link')}</Link>
                    )}
                </div>
                {where.staffHref !== null && (
                    <div className="idc-where">
                        <h2>
                            <Headset className="h-4 w-4" strokeWidth={1.7} aria-hidden />
                            {t('console.roles_where_staff_title')}
                        </h2>
                        <p>{t('console.roles_where_staff_body')}</p>
                        <Link href={where.staffHref}>
                            {t('console.roles_where_staff_link', {
                                count: where.staffCount ?? 0,
                            })}
                        </Link>
                    </div>
                )}
            </section>

            <section className="mt-8" aria-labelledby="roles-app-custom">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="roles-app-custom" className="text-base font-semibold">
                        {t('console.roles_app_custom_title')}
                    </h2>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('console.roles_app_custom_lead')}
                    </p>
                </div>

                <nav className="cbx-tabs mt-3" aria-label={t('console.roles_source_label')}>
                    <SourceTab
                        href={withSource(null)}
                        current={source === null}
                        label={t('console.apps_group_all')}
                        count={sourceCounts.custom + sourceCounts.app}
                    />
                    <SourceTab
                        href={withSource('custom')}
                        current={source === 'custom'}
                        label={t('console.roles_source_custom')}
                        count={sourceCounts.custom}
                    />
                    <SourceTab
                        href={withSource('app')}
                        current={source === 'app'}
                        label={t('console.roles_source_app')}
                        count={sourceCounts.app}
                    />
                </nav>

                <div className="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <Input
                        type="search"
                        className="md:max-w-sm"
                        placeholder={t('console.search_by_name_4ae2b333')}
                        aria-label={t('console.search_roles_848b9c5d')}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    {organizationFilter !== null && (
                        <FilterChips>
                            <OrganizationFilterChip filter={organizationFilter} />
                        </FilterChips>
                    )}
                </div>
                {/*
                    SC 4.1.3: the list re-renders on a debounced keystroke with no focus
                    change, so the result count is the only thing that can report the
                    filter narrowed to nothing.
                */}
                <output aria-live="polite" className="sr-only">
                    {rich('console.search_results_found', { count: pagination.total })}
                </output>

                <div className="idc-rows mt-4">
                    {roles.length === 0 ? (
                        search !== '' || source !== null ? (
                            <EmptyState
                                icon="search"
                                title={t('console.no_matches_2df01a03')}
                                description={
                                    search !== ''
                                        ? t(
                                              'console.no_roles_match_part1_try_a_different_name_135af768',
                                              { part1: search },
                                          )
                                        : t('console.apps_no_match_filters')
                                }
                            />
                        ) : (
                            <EmptyState
                                icon="shield"
                                equivalent="roles.create"
                                title={t('console.no_roles_yet_4fbdeea7')}
                                help={help}
                                description={t(
                                    'console.without_roles_everyone_who_can_sign_in_gets_whatever_an_dc1418f9',
                                )}
                                steps={[
                                    t(
                                        'console.let_your_apps_declare_the_roles_they_understand_those_a_719f1d4a',
                                    ),
                                    t(
                                        'console.or_create_a_role_here_and_compose_it_from_the_permissio_ed2a324a',
                                    ),
                                    t(
                                        'console.assign_it_to_people_the_role_travels_with_them_into_eve_a3f3f862',
                                    ),
                                ]}
                                actions={newRole}
                            />
                        )
                    ) : (
                        roles.map((role, index) => {
                            const previous = roles[index - 1];
                            const section = sectionOf(role);
                            const heading =
                                index === 0 ||
                                previous === undefined ||
                                sectionOf(previous) !== section;

                            return (
                                <Fragment key={role.id}>
                                    {heading && (
                                        <div className="idc-rows-group">
                                            <h3>
                                                {role.declaredByApp
                                                    ? t('console.roles_section_app', {
                                                          app: role.app ?? '',
                                                      })
                                                    : t('console.roles_section_custom')}
                                            </h3>
                                            <p>
                                                {role.declaredByApp
                                                    ? t('console.roles_section_app_lead')
                                                    : t('console.roles_section_custom_lead')}
                                            </p>
                                        </div>
                                    )}
                                    <RoleLink role={role} />
                                </Fragment>
                            );
                        })
                    )}
                </div>

                <div className="mt-4">
                    <Pagination pagination={pagination} href={(page) => listHref(search, page)} />
                </div>
            </section>

            <BuiltInRoles builtIn={builtIn} />

            {sample !== null && <TokenShape sample={sample} />}

            <div className="mt-8">
                <AccessModelGuide current="roles" />
            </div>
        </>
    );
}

/** Which section a row is drawn under: custom roles, or one app's declared roles. */
function sectionOf(role: RoleRow): string {
    return role.declaredByApp ? `app:${role.app ?? ''}` : 'custom';
}

function SourceTab({
    href,
    current,
    label,
    count,
}: {
    href: string;
    current: boolean;
    label: string;
    count: number;
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
            <span className="idc-tab-count">{count}</span>
        </Link>
    );
}

/**
 * THE BUILT-IN ROLES as a table of what each may do here — read from the roles themselves
 * on the server, so it cannot drift from what is enforced. Every member holds exactly one.
 */
function BuiltInRoles({ builtIn }: { builtIn: Props['builtIn'] }) {
    const { t } = useTranslator();

    return (
        <Panel
            className="mt-8"
            title={t('console.roles_builtin_title')}
            description={t('console.roles_builtin_lead')}
            flush
        >
            <div className="overflow-x-auto">
                <table className="idc-matrix">
                    <caption className="sr-only">{t('console.roles_builtin_title')}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{t('console.roles_builtin_ability')}</th>
                            {builtIn.roles.map((role) => (
                                <th key={role.value} scope="col">
                                    {role.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {builtIn.abilities.map((ability) => (
                            <tr key={ability.label}>
                                <th scope="row" style={{ fontWeight: 400 }}>
                                    {ability.label}
                                </th>
                                {builtIn.roles.map((role) => {
                                    const may = ability.holders.includes(role.value);

                                    return (
                                        <td key={role.value} className={may ? 'is-yes' : 'is-no'}>
                                            {may ? (
                                                <Check
                                                    className="inline h-4 w-4"
                                                    strokeWidth={2}
                                                    aria-label={t('console.yes_85a39ab3')}
                                                />
                                            ) : (
                                                <Minus
                                                    className="inline h-4 w-4"
                                                    strokeWidth={1.6}
                                                    aria-label={t('console.no_1ea442a1')}
                                                />
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/** One role: the whole row is the link to it. */
function RoleLink({ role }: { role: RoleRow }) {
    const { t } = useTranslator();
    const RoleIcon = role.declaredByApp ? Lock : role.staffOnly ? Headset : ShieldCheck;

    return (
        <Link href={role.href} className="idc-row">
            <span className="idc-app-mark idc-app-mark--tile h-9 w-9" aria-hidden="true">
                <RoleIcon className="h-4 w-4" strokeWidth={1.7} />
            </span>

            <div className="idc-row-main">
                <span className="idc-row-title">{role.name}</span>
                <span className="idc-row-sub">
                    <span>
                        {role.description ??
                            (role.permissions.length > 0
                                ? role.permissions.join(', ')
                                : t('console.no_permissions_yet_340e092f'))}
                    </span>
                </span>
            </div>

            <div className="idc-row-meta">
                {role.declaredByApp ? (
                    <Badge>
                        <Lock className="h-3 w-3" aria-hidden />
                        {t('console.roles_badge_app')}
                    </Badge>
                ) : (
                    !role.mayCompose && (
                        <Badge>{t('console.managed_for_the_environment_502c8ccb')}</Badge>
                    )
                )}
                {role.app !== null && !role.declaredByApp && (
                    <Badge>{t('console.roles_badge_app_only', { app: role.app })}</Badge>
                )}
                {role.staffOnly && (
                    <Badge tone="info">{t('console.admins_support_only_bc5dfbd2')}</Badge>
                )}
                {role.organization !== null ? (
                    <Badge>
                        <Icon name="building" className="w-3 h-3" />
                        {role.organization}
                    </Badge>
                ) : (
                    role.environmentWide &&
                    !role.declaredByApp && <Badge>{t('console.environment_wide_cd1204b6')}</Badge>
                )}
                <span className="idc-row-when">
                    <KeyRound className="mr-1 inline h-3 w-3" aria-hidden />
                    {t('console.roles_count_permissions', { count: role.permissionCount })}
                    {' · '}
                    {t('console.roles_count_holders', { count: role.holderCount })}
                </span>
            </div>

            <ChevronRight className="idc-row-chevron h-4 w-4" aria-hidden />
        </Link>
    );
}

/**
 * What the app on the other end actually receives.
 *
 * The page above says a role is "a label stamped into the token" and then never shows the
 * token, so the developer reading this still has to guess which claim to read — and the
 * two commonest guesses, `scope` and a nested `authorization` object, are both wrong.
 */
function TokenShape({ sample }: { sample: { role: string; permissions: string[] } }) {
    const { t, rich } = useTranslator();
    const quoted = sample.permissions.map((permission) => `"${permission}"`).join(', ');

    return (
        <details className="card mt-8 p-4">
            <summary className="text-sm font-medium cursor-pointer">
                {t('console.what_your_app_receives_20810522')}
            </summary>

            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('console.roles_and_permissions_arrive_in_the_access_token_as_two_0107aa88')}
            </p>
            <Snippet>
                {t('console._sub_the_person_s_id_org_the_organization_they_are_acti_2e2ddc7f', {
                    part1: sample.role,
                    part2: quoted,
                })}
            </Snippet>

            {/*
                SAID HERE, because it is the question people arrive with and the answer
                lived in a code comment. An app that expects "groups" — Kubernetes,
                Grafana, Vault, and most SaaS predating this vocabulary — is asking for
                these same roles under the name its ecosystem uses. There is nothing
                separate to create, and looking for a Groups page is the wrong search.
                (Directory groups, on Directory Sync, are the opposite direction: an
                upstream provider's groups mapped ONTO these roles, and they never reach a
                token themselves.)
            */}
            <p className="mt-3 text-sm">
                {rich('console._part1_tick_the_part2_part3_scope_when_you_register_the_ef0603eb', {
                    part1: <b>{t('console.if_your_app_expects_groups_these_are_them_09efb592')}</b>,
                    part2: ' ',
                    part3: <code className="mono">{'groups'}</code>,
                    part4: <code className="mono">{'groups'}</code>,
                })}
            </p>
            <Snippet>{t('console._groups_part1_39d243e0', { part1: sample.role })}</Snippet>

            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                {rich('console._part1_the_part2_claim_is_a_different_thing_it_is_what_c15748b6', {
                    part1: sample.permissions.length === 0 && (
                        <>
                            {rich(
                                'console._part1_has_no_permissions_yet_so_part2_part3_arrives_em_a819e0c7',
                                {
                                    part1: <b>{sample.role}</b>,
                                    part2: ' ',
                                    part3: <code className="mono">{'permissions'}</code>,
                                    part4: ' ',
                                },
                            )}
                        </>
                    ),
                    part2: <code className="mono">{'scope'}</code>,
                    part3: ' ',
                    part4: <em>{t('console.app_a172cedc')}</em>,
                    part5: <em>{t('console.person_38a81e87')}</em>,
                })}
            </p>
        </details>
    );
}

function Snippet({ children }: { children: string }) {
    const { t } = useTranslator();
    return (
        <section
            // Scrollable sideways, so reachable by keyboard: a long line is read by scrolling it
            // (WCAG 2.1.1; axe scrollable-region-focusable), which the lint rule does not know.
            // oxlint-disable-next-line jsx-a11y/no-noninteractive-tabindex
            tabIndex={0}
            aria-label={t('console.permissions_as_code_d6c35b7e')}
            className="mt-3 rounded-lg p-3 overflow-x-auto text-xs mono"
            style={{
                background: 'var(--surface-2)',
                border: '1px solid var(--border)',
                lineHeight: 1.6,
            }}
        >
            <pre className="m-0">{children}</pre>
        </section>
    );
}

RolesIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
