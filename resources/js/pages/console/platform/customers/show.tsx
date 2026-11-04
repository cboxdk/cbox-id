import { useConsoleCount } from '@/i18n/count';
import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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
const TARGET_COPY = (tc: Translator['t']) =>
    tc('console.every_page_you_open_from_now_on_organization_7ba726d3');

const OPEN_COPY = (tc: Translator['t']) =>
    tc('console.the_console_is_pointed_at_this_environment_a_4d2a0145');

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
    const { t: tc } = useTranslator();
    const plural = useConsoleCount();

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
                    <span aria-hidden="true">{tc('console.larr_848fc0e7')}</span>
                    {tc('console.back_to_workspaces_279ec831')}
                </Link>
            </div>

            {/*
                No hand-written eyebrow: the route is named `platform.workspaces.show`, so the
                nav registry already owns it under Platform › Customers and the header reads
                the label from there.
            */}
            <PageHeader
                description={tc('console.one_workspace_on_this_install_its_team_its_p_e343ed1c')}
                actions={
                    <Button
                        variant={customer.active ? 'danger' : 'primary'}
                        onClick={() => setSuspending(true)}
                    >
                        {customer.active
                            ? tc('console.suspend_4948e134')
                            : tc('console.reactivate_2dc7478f')}
                    </Button>
                }
            />

            <Panel title={customer.name} className="mb-5 mt-8">
                <div className="flex flex-wrap items-center gap-2 mb-4">
                    {customer.active ? (
                        <Pill tone="success">{tc('console.active_92340695')}</Pill>
                    ) : (
                        <Pill tone="destructive">{tc('console.suspended_e392a389')}</Pill>
                    )}
                </div>

                <KvList>
                    <Kv label={tc('console.organization_id_1f466322')}>{customer.id}</Kv>
                    <Kv label={tc('console.members_1044a4c0')} prose>
                        {memberTotal}
                    </Kv>
                    <Kv label={tc('console.projects_04e2a972')} prose>
                        {projects.length}
                    </Kv>
                    <Kv label={tc('console.environments_07437cd6')} prose>
                        {environmentTotal}
                    </Kv>
                    {customer.createdAt !== null && (
                        <Kv label={tc('console.created_d70b9e24')}>{customer.createdAt}</Kv>
                    )}
                </KvList>
            </Panel>

            <Panel
                title={tc('console.team_5985039f')}
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
                        {tc('console.nobody_is_on_this_workspace_it_has_no_owner__554101fb')}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The people on ${customer.name}, and what each may reach.`}>
                            <thead>
                                <tr>
                                    <Th>{tc('console.member_7c968fb7')}</Th>
                                    <Th>{tc('console.built_in_role_9f680a16')}</Th>
                                    <Th>{tc('console.status_920e413c')}</Th>
                                    <Th>{tc('console.environments_07437cd6')}</Th>
                                    <Th>{tc('console.last_sign_in_0b70af7a')}</Th>
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
                                                ? tc('console.all_a52ace42')
                                                : tc('console.selected_only_e624c530')}
                                        </Td>
                                        <Td
                                            className="whitespace-nowrap text-xs"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {member.lastLogin ?? tc('console.never_6300ef80')}
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
                        {tc('console.projects_amp_environments_bba149d1')}
                    </h2>
                    <output className="text-xs" style={{ color: 'var(--faint)' }}>
                        {plural(projects.length, 'project')},{' '}
                        {plural(environmentTotal, 'environment')}
                    </output>
                </div>

                {projects.length === 0 && unfiledEnvironments.length === 0 && (
                    <EmptyState
                        icon="layers"
                        title={tc('console.no_projects_yet_f83c8065')}
                        description={tc(
                            'console.a_project_is_one_idp_product_this_workspace__a5e40dff',
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
                                    <Pill tone="success">{tc('console.active_92340695')}</Pill>
                                ) : (
                                    <Pill tone="destructive">
                                        {tc('console.suspended_e392a389')}
                                    </Pill>
                                )}
                            </span>
                        }
                        action={
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {project.environments.length}
                                {tc('console.of_a4282e4b')}
                                {project.environmentLimit} {tc('console.environments_84c2d9c8')}
                                {project.remaining}
                                {tc('console.left_on_plan_6bf538d4')}
                            </span>
                        }
                    >
                        {project.environments.length === 0 ? (
                            <div
                                className="px-5 py-8 text-center text-sm"
                                style={{ color: 'var(--faint)' }}
                            >
                                {tc(
                                    'console.this_project_has_no_environments_yet_nothing_a2247eb5',
                                )}
                            </div>
                        ) : (
                            <EnvironmentTable
                                caption={`Environments under ${project.name}.`}
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
                        title={tc('console.not_in_a_project_894f81c5')}
                        action={<Pill tone="warning">{tc('console.unfiled_d64dc9ae')}</Pill>}
                    >
                        <div className="px-5 pt-4 text-sm" style={{ color: 'var(--muted)' }}>
                            {tc('console.this_workspace_owns_02f34b4d')}{' '}
                            {plural(unfiledEnvironments.length, 'environment')}
                            {tc('console.that_no_project_holds_so_nothing_bills_for_a4fae307')}{' '}
                            {unfiledEnvironments.length === 1
                                ? tc('console.it_2ad8a704')
                                : tc('console.them_c9a8dc33')}
                            {tc('console.and_2e5f7696')}{' '}
                            {unfiledEnvironments.length === 1
                                ? tc('console.it_does_386ef68e')
                                : tc('console.they_do_b2ef8abf')}
                            {tc('console.not_appear_on_the_customer_rsquo_s_own_proje_d4a73b77')}{' '}
                            {unfiledEnvironments.length === 1
                                ? tc('console.it_2ad8a704')
                                : tc('console.them_c9a8dc33')}
                            .
                        </div>

                        <div className="mt-4">
                            <EnvironmentTable
                                caption={tc(
                                    'console.environments_this_workspace_owns_that_no_pro_c28359ff',
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
                {tc('console.suspension_is_the_only_thing_this_page_chang_4b1a3610')}
            </p>

            <Dialog
                open={suspending}
                onOpenChange={setSuspending}
                title={`${customer.active ? 'Suspend' : 'Reactivate'} ${customer.name}?`}
                description={
                    customer.active
                        ? tc('console.its_value0_are_signed_out_and_all_value1_it__24f1c9af', {
                              value0: plural(memberTotal, 'member'),
                              value1: plural(environmentTotal, 'environment'),
                          })
                        : tc('console.its_members_can_sign_in_again_and_its_enviro_d369bd33')
                }
                footer={
                    <>
                        <Button onClick={() => setSuspending(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant={customer.active ? 'danger' : 'primary'}
                            onClick={() => {
                                setSuspending(false);
                                router.post(toggleHref, {}, { preserveScroll: true });
                            }}
                        >
                            {customer.active
                                ? tc('console.suspend_4948e134')
                                : tc('console.reactivate_2dc7478f')}
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
                            {tc('console.cancel_19766ed6')}
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
    const { t: tc } = useTranslator();

    return (
        <div className="overflow-x-auto">
            <Table caption={caption}>
                <thead>
                    <tr>
                        <Th>{tc('console.environment_9e471951')}</Th>
                        <Th>{tc('console.domain_79fa3361')}</Th>
                        <Th className="text-right">{tc('console.orgs_ff9e49b5')}</Th>
                        <Th className="text-right">{tc('console.users_6b0cc904')}</Th>
                        <Th>
                            <span className="sr-only">{tc('console.actions_ff8059dc')}</span>
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
                                                    {tc('console.target_978354db')}
                                                </Pill>
                                            )}
                                            {environment.sandbox && (
                                                <Pill dot={false} className="align-middle ml-1">
                                                    {tc('console.sandbox_67fc6249')}
                                                </Pill>
                                            )}
                                            {!environment.serving && (
                                                <Pill
                                                    tone="destructive"
                                                    className="align-middle ml-1"
                                                >
                                                    {tc('console.suspended_e392a389')}
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
                                    tc('console.none_served_on_the_fallback_host_7c84367e')}
                            </Td>
                            <Td className="text-right tabular-nums">{environment.orgs}</Td>
                            <Td className="text-right tabular-nums">{environment.users}</Td>
                            <Td className="text-right whitespace-nowrap">
                                <Button
                                    size="sm"
                                    aria-label={tc(
                                        'console.open_the_organizations_in_value0_bfb4f4bd',
                                        { value0: environment.name },
                                    )}
                                    onClick={() =>
                                        onConfirm({
                                            href: environment.openHref,
                                            title: `Open ${environment.name}?`,
                                            description: OPEN_COPY(tc),
                                            action: tc('console.open_organizations_c2db39e8'),
                                        })
                                    }
                                >
                                    {tc('console.open_organizations_c2db39e8')}
                                </Button>

                                {openOnly ? null : environment.isTarget ? (
                                    <span
                                        className="text-xs ml-2"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {tc('console.target_978354db')}
                                    </span>
                                ) : (
                                    <Button
                                        size="sm"
                                        className="ml-2"
                                        aria-label={tc(
                                            'console.point_this_console_at_value0_7211e979',
                                            { value0: environment.name },
                                        )}
                                        onClick={() =>
                                            onConfirm({
                                                href: environment.targetHref,
                                                title: `Point this console at ${environment.name}?`,
                                                description: TARGET_COPY(tc),
                                                action: 'Target',
                                            })
                                        }
                                    >
                                        {tc('console.target_978354db')}
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
