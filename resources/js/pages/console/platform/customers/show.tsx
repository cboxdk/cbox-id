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

function plural(count: number, noun: string): string {
    return `${count} ${count === 1 ? noun : `${noun}s`}`;
}

/** What a control that repoints the console has to say before it does it. */
const TARGET_COPY =
    'Every page you open from now on — organizations, usage, tenant detail — reads that plane instead of the current one. Nothing is changed in either.';

const OPEN_COPY =
    'The console is pointed at this plane and every page you open from now on reads it instead of the current one. Nothing is changed in either.';

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
                    <span aria-hidden="true">&larr;</span> Back to customers
                </Link>
            </div>

            {/*
                No hand-written eyebrow: the route is named `platform.customers.show`, so the
                nav registry already owns it under Platform › Customers and the header reads
                the label from there.
            */}
            <PageHeader
                description="One customer on this install — its team, its projects, and every environment those projects own. Suspending it signs out its members and stops all of them serving auth."
                actions={
                    <Button
                        variant={customer.active ? 'danger' : 'primary'}
                        onClick={() => setSuspending(true)}
                    >
                        {customer.active ? 'Suspend' : 'Reactivate'}
                    </Button>
                }
            />

            <Panel title={customer.name} className="mb-5 mt-8">
                <div className="flex flex-wrap items-center gap-2 mb-4">
                    {customer.active ? (
                        <Pill tone="success">Active</Pill>
                    ) : (
                        <Pill tone="destructive">Suspended</Pill>
                    )}
                </div>

                <KvList>
                    <Kv label="Organization ID">{customer.id}</Kv>
                    <Kv label="Members" prose>
                        {memberTotal}
                    </Kv>
                    <Kv label="Projects" prose>
                        {projects.length}
                    </Kv>
                    <Kv label="Environments" prose>
                        {environmentTotal}
                    </Kv>
                    {customer.createdAt !== null && <Kv label="Created">{customer.createdAt}</Kv>}
                </KvList>
            </Panel>

            <Panel
                title="Team"
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
                        Nobody is on this customer — it has no owner, so nobody can sign in to
                        administer it. That is a provisioning failure worth chasing, not an empty
                        list.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The people on ${customer.name}, and what each may reach.`}>
                            <thead>
                                <tr>
                                    <Th>Member</Th>
                                    <Th>Role</Th>
                                    <Th>Status</Th>
                                    <Th>Environments</Th>
                                    <Th>Last sign-in</Th>
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
                                            {member.allEnvironments ? 'All' : 'Selected only'}
                                        </Td>
                                        <Td
                                            className="whitespace-nowrap text-xs"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {member.lastLogin ?? 'Never'}
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
                    <h2 className="text-sm font-semibold">Projects &amp; environments</h2>
                    <output className="text-xs" style={{ color: 'var(--faint)' }}>
                        {plural(projects.length, 'project')},{' '}
                        {plural(environmentTotal, 'environment')}
                    </output>
                </div>

                {projects.length === 0 && unfiledEnvironments.length === 0 && (
                    <EmptyState
                        icon="layers"
                        title="No projects yet"
                        description="A project is one IdP product this customer owns, and it is what environments hang off — so until the customer creates one from its own workspace, there is nothing here to target or open. Nothing on this screen creates one on the customer's behalf."
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
                                    <Pill tone="success">Active</Pill>
                                ) : (
                                    <Pill tone="destructive">Suspended</Pill>
                                )}
                            </span>
                        }
                        action={
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {project.environments.length} of {project.environmentLimit}{' '}
                                environments · {project.remaining} left on plan
                            </span>
                        }
                    >
                        {project.environments.length === 0 ? (
                            <div
                                className="px-5 py-8 text-center text-sm"
                                style={{ color: 'var(--faint)' }}
                            >
                                This project has no environments yet — nothing under it serves
                                auth.
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
                        title="Not in a project"
                        action={<Pill tone="warning">Unfiled</Pill>}
                    >
                        <div className="px-5 pt-4 text-sm" style={{ color: 'var(--muted)' }}>
                            This customer owns{' '}
                            {plural(unfiledEnvironments.length, 'environment')} that no project
                            holds, so nothing bills for{' '}
                            {unfiledEnvironments.length === 1 ? 'it' : 'them'} and{' '}
                            {unfiledEnvironments.length === 1 ? 'it does' : 'they do'} not appear
                            on the customer&rsquo;s own project pages. Shown here rather than left
                            invisible; nothing on this screen reassigns{' '}
                            {unfiledEnvironments.length === 1 ? 'it' : 'them'}.
                        </div>

                        <div className="mt-4">
                            <EnvironmentTable
                                caption="Environments this customer owns that no project holds."
                                environments={unfiledEnvironments}
                                onConfirm={setConfirming}
                                openOnly
                            />
                        </div>
                    </Panel>
                )}
            </section>

            <p className="mt-4 text-xs" style={{ color: 'var(--faint)' }}>
                Suspension is the only thing this page changes, and it is reversible. Targeting and
                opening an environment move the console&rsquo;s own view; they change nothing in
                either plane.
            </p>

            <Dialog
                open={suspending}
                onOpenChange={setSuspending}
                title={`${customer.active ? 'Suspend' : 'Reactivate'} ${customer.name}?`}
                description={
                    customer.active
                        ? `Its ${plural(memberTotal, 'member')} are signed out and all ${plural(environmentTotal, 'environment')} it owns stop serving auth on the next request. You can reactivate it here.`
                        : 'Its members can sign in again and its environments resume serving auth.'
                }
                footer={
                    <>
                        <Button onClick={() => setSuspending(false)}>Cancel</Button>
                        <Button
                            variant={customer.active ? 'danger' : 'primary'}
                            onClick={() => {
                                setSuspending(false);
                                router.post(toggleHref, {}, { preserveScroll: true });
                            }}
                        >
                            {customer.active ? 'Suspend' : 'Reactivate'}
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
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
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
    return (
        <div className="overflow-x-auto">
            <Table caption={caption}>
                <thead>
                    <tr>
                        <Th>Environment</Th>
                        <Th>Domain</Th>
                        <Th className="text-right">Orgs</Th>
                        <Th className="text-right">Users</Th>
                        <Th>
                            <span className="sr-only">Actions</span>
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
                                                <Pill
                                                    tone="success"
                                                    className="align-middle ml-1"
                                                >
                                                    Target
                                                </Pill>
                                            )}
                                            {environment.sandbox && (
                                                <Pill dot={false} className="align-middle ml-1">
                                                    Sandbox
                                                </Pill>
                                            )}
                                            {!environment.serving && (
                                                <Pill
                                                    tone="destructive"
                                                    className="align-middle ml-1"
                                                >
                                                    Suspended
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
                                {environment.domain ?? 'None — served on the fallback host'}
                            </Td>
                            <Td className="text-right tabular-nums">{environment.orgs}</Td>
                            <Td className="text-right tabular-nums">{environment.users}</Td>
                            <Td className="text-right whitespace-nowrap">
                                <Button
                                    size="sm"
                                    aria-label={`Open the tenants in ${environment.name}`}
                                    onClick={() =>
                                        onConfirm({
                                            href: environment.openHref,
                                            title: `Open ${environment.name}?`,
                                            description: OPEN_COPY,
                                            action: 'Open tenants',
                                        })
                                    }
                                >
                                    Open tenants
                                </Button>

                                {openOnly ? null : environment.isTarget ? (
                                    <span
                                        className="text-xs ml-2"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        Target
                                    </span>
                                ) : (
                                    <Button
                                        size="sm"
                                        className="ml-2"
                                        aria-label={`Point this console at ${environment.name}`}
                                        onClick={() =>
                                            onConfirm({
                                                href: environment.targetHref,
                                                title: `Point this console at ${environment.name}?`,
                                                description: TARGET_COPY,
                                                action: 'Target',
                                            })
                                        }
                                    >
                                        Target
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
