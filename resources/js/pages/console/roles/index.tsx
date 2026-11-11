import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import type {
    HelpContent,
    OrganizationFilter,
    PageProps,
    Pagination as PaginationState,
} from '@/types';
import {
    Badge,
    Button,
    Combobox,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    AccessModelGuide,
} from '@/ui';

interface Offerable {
    id: string;
    name: string;
    description: string | null;
}

interface RoleRow {
    id: string;
    name: string;
    description: string | null;
    /** Declared by an application, which is its source of truth — read-only here. */
    declaredByApp: boolean;
    /** The app whose tokens it is stamped into, or null for every app. */
    app: string | null;
    environmentWide: boolean;
    /**
     * The organization that owns it — set only when every organization is in view, where
     * two tenants' "Editor" would otherwise be two identical rows.
     */
    organization: string | null;
    permissions: string[];
    moreCount: number;
    offerable: Offerable[];
    mayCompose: boolean;
    href: string;
    grantHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    roles: RoleRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    sample: { role: string; permissions: string[] } | null;
    createHref: string;
    consoleAccessHref: string | null;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function RolesIndex({
    help,
    roles,
    pagination,
    search,
    mayAdminister,
    sample,
    createHref,
    consoleAccessHref,
    organizationFilter,
}: Props) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.what_people_can_do_inside_your_apps_you_assign_roles_he_c31f1a20',
                )}
                actions={
                    mayAdminister ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_new_role_db15d053', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            {/* The one paragraph that removes the confusion this page arrives with. */}
            <div
                className="card p-4 mt-6"
                style={{ background: 'var(--accent-soft)', borderColor: 'var(--accent-edge)' }}
            >
                <div className="flex items-start gap-3">
                    <span
                        className="grid place-items-center rounded-lg shrink-0"
                        style={{
                            width: '2rem',
                            height: '2rem',
                            background: 'var(--card)',
                            color: 'var(--primary)',
                        }}
                    >
                        <Icon name="key" className="w-4 h-4" />
                    </span>
                    <p className="text-sm">
                        {consoleRich(
                            'console._part1_a_role_is_a_label_stamped_into_the_token_the_one_92a78021',
                            {
                                part1: (
                                    <b>
                                        {consoleT(
                                            'console.cbox_id_assigns_roles_your_app_decides_what_they_can_do_840ee10f',
                                        )}
                                    </b>
                                ),
                                part2: ' ',
                                part3:
                                    consoleAccessHref !== null ? (
                                        <Link
                                            href={consoleAccessHref}
                                            className="underline"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            {consoleT('console.built_in_role_59a06e31')}
                                        </Link>
                                    ) : (
                                        consoleNestedText('console.built_in_role_59a06e31')
                                    ),
                                part4: ' ',
                            },
                        )}
                    </p>
                </div>
            </div>

            {sample !== null && <TokenShape sample={sample} />}

            <div className="mt-6 space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                    aria-label={consoleT('console.search_roles_848b9c5d')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list re-renders on a debounced keystroke with no focus
                    change, so the result count is the only thing that can report the
                    filter narrowed to nothing.
                */}
                <output aria-live="polite" className="sr-only">
                    {consoleRich('console.search_results_found', { count: pagination.total })}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {roles.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleT('console.no_matches_2df01a03')}
                            description={consoleFinalText(
                                'console.no_roles_match_part1_try_a_different_name_135af768',
                                { part1: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            equivalent="roles.create"
                            title={consoleT('console.no_roles_yet_4fbdeea7')}
                            help={help}
                            description={consoleT(
                                'console.without_roles_everyone_who_can_sign_in_gets_whatever_an_dc1418f9',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.let_your_apps_declare_the_roles_they_understand_those_a_719f1d4a',
                                ),
                                consoleRuntimeText(
                                    'console.or_create_a_role_here_and_compose_it_from_the_permissio_ed2a324a',
                                ),
                                consoleRuntimeText(
                                    'console.assign_it_to_people_the_role_travels_with_them_into_eve_a3f3f862',
                                ),
                            ]}
                            actions={
                                mayAdminister ? (
                                    <Button asChild variant="primary">
                                        <Link href={createHref}>
                                            {consoleRich('console._part1_new_role_db15d053', {
                                                part1: <Icon name="plus" className="w-4 h-4" />,
                                            })}
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    roles.map((role, index) => (
                        <Row
                            key={role.id}
                            role={role}
                            mayAdminister={mayAdminister}
                            last={index === roles.length - 1}
                        />
                    ))
                )}
            </div>

            <Legend roles={roles} />

            <Pagination pagination={pagination} href={(page) => listHref(search, page)} />
            <div className="mt-8">
                <AccessModelGuide current="roles" />
            </div>
        </>
    );
}

/**
 * What the badges on the rows above mean, said once.
 *
 * These were `title` attributes on each badge, which is the one place they could not do
 * their job: a tooltip is unreachable by touch, invisible to most screen readers, and gone
 * the moment the pointer moves — and "Environment-wide" is not decoration, it is the
 * difference between a role you may compose and one you may only hold.
 *
 * IT SAYS DIFFERENT THINGS ON THE TWO PLANES. From an organization, an environment-wide
 * role means "you may use this but not change it". From the environment it means the
 * opposite, and something more: it is the only kind that can be granted to a person across
 * every organization at once. Which sentence applies is exactly whether this administrator
 * may compose one, so the rows already carry the answer.
 */
function Legend({ roles }: { roles: RoleRow[] }) {
    const { t: consoleT } = useConsoleTranslator();
    const declared = roles.some((role) => role.declaredByApp);
    const wide = roles.filter((role) => role.environmentWide);

    if (!declared && wide.length === 0) {
        return null;
    }

    return (
        <dl className="mt-4 space-y-1.5 text-sm" style={{ color: 'var(--muted-foreground)' }}>
            {declared && (
                <div className="flex items-start gap-2">
                    <dt className="shrink-0">
                        <Badge>{consoleT('console.app_0d04bfeb')}</Badge>
                    </dt>
                    <dd>
                        {consoleT(
                            'console.declared_by_the_application_which_is_its_source_of_trut_b3e04ae9',
                        )}
                    </dd>
                </div>
            )}
            {wide.length > 0 && (
                <div className="flex items-start gap-2">
                    <dt className="shrink-0">
                        <Badge>{consoleT('console.environment_wide_cd1204b6')}</Badge>
                    </dt>
                    <dd>
                        {wide.some((role) => role.mayCompose)
                            ? consoleT(
                                  'console.defined_for_the_whole_environment_every_organization_ca_8039a976',
                              )
                            : consoleT(
                                  'console.owned_by_the_environment_it_applies_here_but_is_not_you_b6293291',
                              )}
                    </dd>
                </div>
            )}
        </dl>
    );
}

/**
 * One role.
 *
 * A row rather than one big link: the permission picker lives inside it, and a combobox
 * nested in an anchor is neither valid markup nor operable by keyboard.
 */
function Row({
    role,
    mayAdminister,
    last,
}: {
    role: RoleRow;
    mayAdminister: boolean;
    last: boolean;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    /*
     * AN EMPTY PICKER MEANS TWO DIFFERENT THINGS. With grants on the role it means they
     * are all held; with none it means the catalogue has nothing for this role at all —
     * and the row said both at once: "No permissions yet." over "Every available
     * permission is already granted." One sentence, the true one.
     */
    const nothingToOffer =
        mayAdminister &&
        role.mayCompose &&
        role.offerable.length === 0 &&
        role.permissions.length === 0 &&
        role.moreCount === 0;

    return (
        <div className="p-4" style={last ? undefined : { borderBottom: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3 flex-wrap">
                <div className="min-w-0 flex-1">
                    <Link href={role.href} className="font-medium truncate">
                        {role.name}
                    </Link>
                    {role.description !== null && (
                        <p
                            className="text-sm truncate"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {role.description}
                        </p>
                    )}
                </div>

                {role.declaredByApp && <Badge>{consoleT('console.app_0d04bfeb')}</Badge>}

                {role.app !== null ? (
                    <Badge>
                        {consoleRich('console._part1_part2_only_5ce234b9', {
                            part1: <Icon name="clients" className="w-3 h-3" />,
                            part2: role.app,
                        })}
                    </Badge>
                ) : (
                    <Badge>{consoleT('console.all_apps_01bed311')}</Badge>
                )}

                {role.environmentWide && (
                    <Badge>{consoleT('console.environment_wide_cd1204b6')}</Badge>
                )}

                {role.organization !== null && (
                    <Badge>
                        <Icon name="members" className="w-3 h-3" />
                        <span className="sr-only">{consoleT('console.organization_5300e286')}</span>
                        {role.organization}
                    </Badge>
                )}

                {/* The chevron promises a page, so it is a link to it — the row holds
                    permission chips of its own and cannot itself be one. */}
                <Link
                    href={role.href}
                    className="cbx-iconbtn shrink-0"
                    aria-label={consoleFinalText('console.open_part1_10c0f8b9', {
                        part1: role.name,
                    })}
                >
                    <Icon
                        name="chevron"
                        className="w-4 h-4"
                        style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                    />
                </Link>
            </div>

            <div className="flex flex-wrap items-center gap-1.5 mt-3">
                {role.permissions.length === 0 ? (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {nothingToOffer
                            ? consoleT(
                                  'console.no_permissions_yet_none_of_your_apps_has_declared_one_t_f84b634e',
                              )
                            : consoleT('console.no_permissions_yet_340e092f')}
                    </span>
                ) : (
                    role.permissions.map((permission) => (
                        <Badge key={permission}>
                            <Icon name="key" className="w-3 h-3" />
                            <span className="mono">{permission}</span>
                        </Badge>
                    ))
                )}
                {role.moreCount > 0 && (
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {consoleRich('console.and_part1_more_1468d675', { part1: role.moreCount })}
                    </span>
                )}
            </div>

            {mayAdminister &&
                role.mayCompose &&
                !nothingToOffer &&
                (role.offerable.length === 0 ? (
                    <p className="text-xs mt-3" style={{ color: 'var(--faint)' }}>
                        {consoleT('console.every_available_permission_is_already_granted_50e66339')}
                    </p>
                ) : (
                    <div className="mt-3" style={{ maxWidth: '22rem' }}>
                        <Combobox
                            value={undefined}
                            aria-label={consoleFinalText(
                                'console.add_a_permission_to_the_part1_role_5684b805',
                                { part1: role.name },
                            )}
                            placeholder={consoleUiText('console._add_a_permission_d9202366')}
                            searchPlaceholder={consoleT('console.search_permissions_267b21e2')}
                            emptyMessage={consoleT('console.no_permission_matches_that_acbdba7a')}
                            options={role.offerable.map((permission) => ({
                                value: permission.id,
                                label: permission.name,
                                keywords: [permission.name],
                                hint: permission.description ?? undefined,
                            }))}
                            onValueChange={(permission) =>
                                router.post(
                                    role.grantHref,
                                    { permission, granted: true },
                                    { preserveScroll: true },
                                )
                            }
                        />
                    </div>
                ))}
        </div>
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const quoted = sample.permissions.map((permission) => `"${permission}"`).join(', ');

    return (
        <details className="card mt-4 p-4">
            <summary className="text-sm font-medium cursor-pointer">
                {consoleT('console.what_your_app_receives_20810522')}
            </summary>

            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {consoleT(
                    'console.roles_and_permissions_arrive_in_the_access_token_as_two_0107aa88',
                )}
            </p>
            <Snippet>
                {consoleFinalText(
                    'console._sub_the_person_s_id_org_the_organization_they_are_acti_2e2ddc7f',
                    { part1: sample.role, part2: quoted },
                )}
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
                {consoleRich(
                    'console._part1_tick_the_part2_part3_scope_when_you_register_the_ef0603eb',
                    {
                        part1: (
                            <b>
                                {consoleT(
                                    'console.if_your_app_expects_groups_these_are_them_09efb592',
                                )}
                            </b>
                        ),
                        part2: ' ',
                        part3: <code className="mono">{'groups'}</code>,
                        part4: <code className="mono">{'groups'}</code>,
                    },
                )}
            </p>
            <Snippet>
                {consoleFinalText('console._groups_part1_39d243e0', { part1: sample.role })}
            </Snippet>

            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                {consoleRich(
                    'console._part1_the_part2_claim_is_a_different_thing_it_is_what_c15748b6',
                    {
                        part1: sample.permissions.length === 0 && (
                            <>
                                {consoleRich(
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
                        part4: <em>{consoleT('console.app_a172cedc')}</em>,
                        part5: <em>{consoleT('console.person_38a81e87')}</em>,
                    },
                )}
            </p>
        </details>
    );
}

function Snippet({ children }: { children: string }) {
    const { t: consoleT } = useConsoleTranslator();
    return (
        <section
            // Scrollable sideways, so reachable by keyboard: a long line is read by scrolling it

            // (WCAG 2.1.1; axe scrollable-region-focusable), which the lint rule does not know.

            // oxlint-disable-next-line jsx-a11y/no-noninteractive-tabindex
            tabIndex={0}
            aria-label={consoleT('console.permissions_as_code_d6c35b7e')}
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
