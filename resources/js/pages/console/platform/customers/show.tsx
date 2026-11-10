import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Dialog,
    EmptyState,
    Kv,
    KvList,
    PageHeader,
    Panel,
    Pill,
    Table,
    Td,
    Th,
} from '@/ui';

interface EnvironmentRow {
    id: string;
    name: string;
    slug: string;
    domain: string | null;
    sandbox: boolean;
    serving: boolean;
    isTarget: boolean;
    orgs: number;
    users: number;
    targetHref: string;
    openHref: string;
}

interface ProjectRow {
    id: string;
    name: string;
    active: boolean;
    environmentLimit: number;
    remaining: number;
    environments: EnvironmentRow[];
}

interface MemberRow {
    id: string;
    email: string | null;
    name: string | null;
    role: string;
    status: string;
    active: boolean;
    allEnvironments: boolean;
    lastLogin: string | null;
}

type Props = PageProps<{
    customer: {
        id: string;
        name: string;
        active: boolean;
        createdAt: string | null;
    };
    members: MemberRow[];
    memberTotal: number;
    projects: ProjectRow[];
    unfiledEnvironments: EnvironmentRow[];
    environmentTotal: number;
    indexHref: string;
    toggleHref: string;
}>;

/** What a control that repoints the console has to say before it does it. */
const TARGET_COPY = (t: Translator['t']) =>
    t('console.every_page_you_open_from_now_on_organizations_usage_org_7ba726d3');

const OPEN_COPY = (t: Translator['t']) =>
    t('console.the_console_is_pointed_at_this_environment_and_every_pa_4d2a0145');

export default function Customer({
    customer,
    members,
    memberTotal,
    projects,
    unfiledEnvironments,
    environmentTotal,
    indexHref,
    toggleHref,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { choice: consoleChoice } = useConsoleTranslator();
    function plural(count: number, noun: 'member' | 'project' | 'environment'): string {
        const keys = {
            member: 'console.count_members',
            project: 'console.count_projects',
            environment: 'console.count_environments',
        } as const;
        return consoleChoice(keys[noun], count);
    }

    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [suspending, setSuspending] = useState(false);
    const [confirming, setConfirming] = useState<{
        href: string;
        title: string;
        description: string;
        action: string;
    } | null>(null);

    return (
        <>
            <div className="mb-5">
                <Link
                    href={indexHref}
                    className="inline-flex items-center gap-1 text-sm"
                    style={{ color: 'var(--muted)' }}
                >
                    {consoleRich('console._part1_back_to_organizations_9fbdab0c', {
                        part1: <span aria-hidden="true">{consoleT('console._larr_848fc0e7')}</span>,
                    })}
                </Link>
            </div>

            {/*
                No hand-written eyebrow: the route is named `platform.workspaces.show`, so the
                nav registry already owns it under Platform › Customers and the header reads
                the label from there.
            */}
            <PageHeader
                description={consoleT(
                    'console.one_organization_on_this_install_its_team_its_projects__42711bef',
                )}
                actions={
                    <Button
                        variant={customer.active ? 'danger' : 'primary'}
                        onClick={() => setSuspending(true)}
                    >
                        {customer.active
                            ? consoleText('console.suspend_4948e134')
                            : consoleText('console.reactivate_2dc7478f')}
                    </Button>
                }
            />

            <Panel title={customer.name} className="mb-5 mt-8">
                <div className="flex flex-wrap items-center gap-2 mb-4">
                    {customer.active ? (
                        <Pill tone="success">{consoleT('console.active_92340695')}</Pill>
                    ) : (
                        <Pill tone="destructive">{consoleT('console.suspended_e392a389')}</Pill>
                    )}
                </div>

                <KvList>
                    <Kv label={consoleT('console.organization_id_1f466322')}>{customer.id}</Kv>
                    <Kv label={consoleT('console.members_1044a4c0')} prose>
                        {memberTotal}
                    </Kv>
                    <Kv label={consoleT('console.projects_04e2a972')} prose>
                        {projects.length}
                    </Kv>
                    <Kv label={consoleT('console.environments_07437cd6')} prose>
                        {environmentTotal}
                    </Kv>
                    {customer.createdAt !== null && (
                        <Kv label={consoleT('console.created_d70b9e24')}>{customer.createdAt}</Kv>
                    )}
                </KvList>
            </Panel>

            <Panel
                title={consoleT('console.team_5985039f')}
                flush
                action={
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {plural(memberTotal, 'member')}
                    </span>
                }
                className="mb-5"
            >
                {members.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        {consoleT(
                            'console.nobody_is_on_this_organization_it_has_no_owner_so_nobod_0a51d66d',
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table
                            caption={consoleFinalText(
                                'console.the_people_on_part1_and_what_each_may_reach_474ab0a4',
                                { part1: customer.name },
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{consoleT('console.member_7c968fb7')}</Th>
                                    <Th>{consoleT('console.built_in_role_9f680a16')}</Th>
                                    <Th>{consoleT('console.status_920e413c')}</Th>
                                    <Th>{consoleT('console.environments_07437cd6')}</Th>
                                    <Th>{consoleT('console.last_sign_in_0b70af7a')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <tr key={member.id}>
                                        <Td>
                                            <p className="font-medium">{member.email ?? '—'}</p>
                                            {member.name !== null && (
                                                <p
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    {member.name}
                                                </p>
                                            )}
                                        </Td>
                                        <Td className="whitespace-nowrap">{member.role}</Td>
                                        <Td className="whitespace-nowrap">
                                            <Pill tone={member.active ? 'success' : 'warning'}>
                                                <span className="capitalize">{member.status}</span>
                                            </Pill>
                                        </Td>
                                        <Td
                                            className="whitespace-nowrap text-xs"
                                            style={{ color: 'var(--muted)' }}
                                        >
                                            {member.allEnvironments
                                                ? consoleText('console.all_a52ace42')
                                                : consoleText('console.selected_only_e624c530')}
                                        </Td>
                                        <Td
                                            className="whitespace-nowrap text-xs"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {member.lastLogin ??
                                                consoleShortText('console.never_6300ef80')}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>

            {/*
                Projects → environments. The walk the counts on the customer list used to
                promise and could not deliver.
            */}
            <section>
                <div className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                    <h2 className="text-sm font-semibold">
                        {consoleT('console.projects_environments_4183db87')}
                    </h2>
                    <output className="text-xs" style={{ color: 'var(--faint)' }}>
                        {plural(projects.length, 'project')},{' '}
                        {plural(environmentTotal, 'environment')}
                    </output>
                </div>

                {projects.length === 0 && unfiledEnvironments.length === 0 && (
                    <EmptyState
                        icon="layers"
                        title={consoleT('console.no_projects_yet_f83c8065')}
                        description={consoleT(
                            'console.a_project_is_one_idp_product_this_organization_owns_and_d87379c8',
                        )}
                    />
                )}

                {projects.map((project) => (
                    <Panel
                        key={project.id}
                        flush
                        className="mb-5"
                        title={
                            <span className="flex flex-wrap items-center gap-2 min-w-0">
                                {project.name}
                                {project.active ? (
                                    <Pill tone="success">
                                        {consoleT('console.active_92340695')}
                                    </Pill>
                                ) : (
                                    <Pill tone="destructive">
                                        {consoleT('console.suspended_e392a389')}
                                    </Pill>
                                )}
                            </span>
                        }
                        action={
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {consoleRich('console.environment_plan_usage', {
                                    used: project.environments.length,
                                    limit: project.environmentLimit,
                                    remaining: project.remaining,
                                })}
                            </span>
                        }
                    >
                        {project.environments.length === 0 ? (
                            <div
                                className="px-5 py-8 text-center text-sm"
                                style={{ color: 'var(--faint)' }}
                            >
                                {consoleT(
                                    'console.this_project_has_no_environments_yet_nothing_under_it_s_a2247eb5',
                                )}
                            </div>
                        ) : (
                            <EnvironmentTable
                                caption={consoleFinalText(
                                    'console.environments_under_part1_e3767a68',
                                    { part1: project.name },
                                )}
                                environments={project.environments}
                                onConfirm={setConfirming}
                            />
                        )}
                    </Panel>
                ))}

                {unfiledEnvironments.length > 0 && (
                    <Panel
                        flush
                        className="mb-5"
                        title={consoleT('console.not_in_a_project_894f81c5')}
                        action={<Pill tone="warning">{consoleT('console.unfiled_d64dc9ae')}</Pill>}
                    >
                        <div className="px-5 pt-4 text-sm" style={{ color: 'var(--muted)' }}>
                            {consoleChoice(
                                'console.unfiled_environments_explanation',
                                unfiledEnvironments.length,
                            )}
                        </div>

                        <div className="mt-4">
                            <EnvironmentTable
                                caption={consoleUiText(
                                    'console.environments_this_organization_owns_that_no_project_hol_a7254a00',
                                )}
                                environments={unfiledEnvironments}
                                onConfirm={setConfirming}
                                openOnly
                            />
                        </div>
                    </Panel>
                )}
            </section>

            <p className="mt-4 text-xs" style={{ color: 'var(--faint)' }}>
                {consoleT(
                    'console.suspension_is_the_only_thing_this_page_changes_and_it_i_4b1a3610',
                )}
            </p>

            <Dialog
                open={suspending}
                onOpenChange={setSuspending}
                title={`${customer.active ? consoleText('console.suspend_4948e134') : consoleText('console.reactivate_2dc7478f')} ${customer.name}?`}
                description={
                    customer.active
                        ? consoleFinalText(
                              'console.its_part1_are_signed_out_and_all_part2_it_owns_stop_ser_c1a0c179',
                              {
                                  part1: plural(memberTotal, 'member'),
                                  part2: plural(environmentTotal, 'environment'),
                              },
                          )
                        : consoleText(
                              'console.its_members_can_sign_in_again_and_its_environments_resu_d369bd33',
                          )
                }
                footer={
                    <>
                        <Button onClick={() => setSuspending(false)}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant={customer.active ? 'danger' : 'primary'}
                            onClick={() => {
                                setSuspending(false);
                                router.post(toggleHref, {}, { preserveScroll: true });
                            }}
                        >
                            {customer.active
                                ? consoleText('console.suspend_4948e134')
                                : consoleText('console.reactivate_2dc7478f')}
                        </Button>
                    </>
                }
            />

            {/*
                Opening or targeting a plane repoints EVERY subsequent read in the console at
                it, so both controls confirm: without one, a slow switch looked like a dead
                button and the operator's next page was quietly about a different estate.
            */}
            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={confirming?.title ?? ''}
                description={confirming?.description ?? ''}
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="primary"
                            onClick={() => {
                                const pending = confirming;
                                setConfirming(null);

                                if (pending !== null) {
                                    router.post(pending.href);
                                }
                            }}
                        >
                            {confirming?.action ?? ''}
                        </Button>
                    </>
                }
            />
        </>
    );
}

function EnvironmentTable({
    caption,
    environments,
    onConfirm,
    openOnly = false,
}: {
    caption: string;
    environments: EnvironmentRow[];
    onConfirm: (pending: {
        href: string;
        title: string;
        description: string;
        action: string;
    }) => void;
    openOnly?: boolean;
}) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleStaticText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="overflow-x-auto">
            <Table caption={caption}>
                <thead>
                    <tr>
                        <Th>{consoleT('console.environment_9e471951')}</Th>
                        <Th>{consoleT('console.domain_79fa3361')}</Th>
                        <Th className="text-right">{consoleT('console.orgs_ff9e49b5')}</Th>
                        <Th className="text-right">{consoleT('console.users_6b0cc904')}</Th>
                        <Th>
                            <span className="sr-only">{consoleT('console.actions_ff8059dc')}</span>
                        </Th>
                    </tr>
                </thead>
                <tbody>
                    {environments.map((environment) => (
                        <tr key={environment.id}>
                            <Td>
                                <div className="flex items-center gap-3">
                                    <span
                                        aria-hidden="true"
                                        className="grid place-items-center rounded-md text-xs font-bold shrink-0"
                                        style={{
                                            width: '1.9rem',
                                            height: '1.9rem',
                                            background: 'var(--accent-soft)',
                                            color: 'var(--accent-strong)',
                                        }}
                                    >
                                        {environment.name.slice(0, 1).toUpperCase()}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="font-semibold">
                                            {environment.name}
                                            {environment.isTarget && (
                                                <Pill tone="success" className="align-middle ml-1">
                                                    {consoleT('console.target_978354db')}
                                                </Pill>
                                            )}
                                            {environment.sandbox && (
                                                <Pill dot={false} className="align-middle ml-1">
                                                    {consoleT('console.sandbox_67fc6249')}
                                                </Pill>
                                            )}
                                            {!environment.serving && (
                                                <Pill
                                                    tone="destructive"
                                                    className="align-middle ml-1"
                                                >
                                                    {consoleT('console.suspended_e392a389')}
                                                </Pill>
                                            )}
                                        </p>
                                        <p
                                            className="text-xs mono"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {environment.slug}
                                        </p>
                                    </div>
                                </div>
                            </Td>
                            <Td style={{ color: 'var(--muted)' }}>
                                {environment.domain ??
                                    consoleRuntimeText(
                                        'console.none_served_on_the_fallback_host_7c84367e',
                                    )}
                            </Td>
                            <Td className="text-right tabular-nums">{environment.orgs}</Td>
                            <Td className="text-right tabular-nums">{environment.users}</Td>
                            <Td className="text-right whitespace-nowrap">
                                <Button
                                    size="sm"
                                    aria-label={consoleFinalText(
                                        'console.open_the_organizations_in_part1_ba9b05cd',
                                        { part1: environment.name },
                                    )}
                                    onClick={() =>
                                        onConfirm({
                                            href: environment.openHref,
                                            title: consoleFinalText('console.open_part1_c8575135', {
                                                part1: environment.name,
                                            }),
                                            description: OPEN_COPY(consoleStaticText),
                                            action: consoleFinalText(
                                                'console.open_organizations_c2db39e8',
                                            ),
                                        })
                                    }
                                >
                                    {consoleT('console.open_organizations_c2db39e8')}
                                </Button>

                                {openOnly ? null : environment.isTarget ? (
                                    <span
                                        className="text-xs ml-2"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {consoleT('console.target_978354db')}
                                    </span>
                                ) : (
                                    <Button
                                        size="sm"
                                        className="ml-2"
                                        aria-label={consoleFinalText(
                                            'console.point_this_console_at_part1_a53811c7',
                                            { part1: environment.name },
                                        )}
                                        onClick={() =>
                                            onConfirm({
                                                href: environment.targetHref,
                                                title: consoleFinalText(
                                                    'console.point_this_console_at_part1_6614bd12',
                                                    { part1: environment.name },
                                                ),
                                                description: TARGET_COPY(consoleStaticText),
                                                action: 'Target',
                                            })
                                        }
                                    >
                                        {consoleT('console.target_978354db')}
                                    </Button>
                                )}
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );
}

Customer.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
