import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, SimplePagination as SimplePaginationState } from '@/types';
import {
    Button,
    Dialog,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Pill,
    SimplePagination,
    Table,
    Td,
    Th,
} from '@/ui';

interface Lineage {
    owner: string;
    organizationId: string | null;
    organizationName: string | null;
    organizationHref: string | null;
    projectName: string | null;
    isPlatformRoot: boolean;
    isUnattached: boolean;
    note: string | null;
}

interface EnvironmentRow {
    id: string;
    name: string;
    qualifiedName: string;
    slug: string;
    domain: string | null;
    orgs: number;
    users: number;
    lineage: Lineage;
    provisionHref: string;
}

type Props = PageProps<{
    environments: EnvironmentRow[];
    pagination: SimplePaginationState;
    search: string;
    activeId: string | null;
    storeHref: string;
    targetHref: string;
    customersHref: string;
}>;

function listHref(search: string, page?: number): string {
    const query = new URLSearchParams();

    if (search !== '') {
        query.set('q', search);
    }

    if (page !== undefined && page > 1) {
        query.set('page', String(page));
    }

    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}

export default function Environments({
    environments,
    pagination,
    search,
    activeId,
    storeHref,
    targetHref,
    customersHref,
}: Props) {
    const [term, setTerm] = useState(search);
    const [creating, setCreating] = useState(false);
    const [provisioning, setProvisioning] = useState<string | null>(null);
    const [targeting, setTargeting] = useState<EnvironmentRow | null>(null);

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
                description="Every isolation plane on this install, and who owns it. Create one, point the console at it, and bootstrap it with an admin."
                actions={
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        <Icon name="plus" className="w-4 h-4" />
                        New environment
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name, slug or domain"
                    aria-label="Search environments"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the table is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.count} {pagination.count === 1 ? 'environment' : 'environments'} on
                    this page.
                </output>
            </div>

            {creating && (
                <CreateEnvironment href={storeHref} onDone={() => setCreating(false)} />
            )}

            {environments.length === 0 ? (
                <div className="mt-8">
                    {search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No environments match “${search}”`}
                            description="Try part of the name, the routing slug, or a custom domain."
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title="No environments yet"
                            description="An environment is one isolation plane — its own users, keys, sign-in and issuer. Create your first one above, then bootstrap it with an organization and an admin."
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    New environment
                                </Button>
                            }
                        />
                    )}
                </div>
            ) : (
                <>
                    <div className="cbx-panel overflow-hidden mt-8">
                        <div className="overflow-x-auto">
                            <Table caption="Environments on this install, with their owner, custom domain and size.">
                                <thead>
                                    <tr>
                                        <Th>Environment</Th>
                                        <Th>Belongs to</Th>
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
                                        <EnvironmentRows
                                            key={environment.id}
                                            environment={environment}
                                            isTarget={environment.id === activeId}
                                            provisioning={provisioning === environment.id}
                                            onProvision={() => setProvisioning(environment.id)}
                                            onProvisionDone={() => setProvisioning(null)}
                                            onTarget={() => setTargeting(environment)}
                                        />
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>

                    <div className="mt-4">
                        <SimplePagination
                            pagination={pagination}
                            noun="environment"
                            href={(page) => listHref(search, page)}
                        />
                    </div>
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                Environments nest under a project, and a project under a{' '}
                <Link href={customersHref} className="underline">
                    customer
                </Link>{' '}
                — start there to see one customer&rsquo;s whole estate. Two rows here never have a
                customer: the <strong>platform root</strong> is the plane this deployment itself
                runs in, where operators and a customer&rsquo;s own people live, and an{' '}
                <strong>unattached</strong> environment has no project, so nothing bills for it and
                no customer reaches it. Neither is an error to fix from this screen, and nothing
                here reassigns either.
            </p>

            {/*
                Repoints EVERY subsequent read in this console at another plane, so it is
                confirmed rather than done on a click: with no confirmation a slow switch
                looked like a dead button, and the operator's next page was quietly about a
                different estate.
            */}
            <Dialog
                open={targeting !== null}
                onOpenChange={(open) => !open && setTargeting(null)}
                title={targeting === null ? '' : `Point this console at ${targeting.name}?`}
                description="Every page you open from now on — organizations, usage, tenant detail — reads that plane instead of the current one. Nothing is changed in either."
                footer={
                    <>
                        <Button onClick={() => setTargeting(null)}>Cancel</Button>
                        <Button
                            variant="primary"
                            onClick={() => {
                                const environment = targeting;
                                setTargeting(null);

                                if (environment !== null) {
                                    router.post(targetHref, { environment: environment.id });
                                }
                            }}
                        >
                            Target
                        </Button>
                    </>
                }
            />
        </>
    );
}

function EnvironmentRows({
    environment,
    isTarget,
    provisioning,
    onProvision,
    onProvisionDone,
    onTarget,
}: {
    environment: EnvironmentRow;
    isTarget: boolean;
    provisioning: boolean;
    onProvision: () => void;
    onProvisionDone: () => void;
    onTarget: () => void;
}) {
    const { lineage } = environment;

    return (
        <>
            <tr>
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
                            {/*
                                The OWNER is part of the name here. "Production" is a name
                                half the customers on an install will have, so the row leads
                                with "Acme / Production" and keeps the bare environment slug
                                underneath it.
                            */}
                            <p className="font-semibold">
                                {environment.qualifiedName}
                                {isTarget && (
                                    <Pill tone="success" className="align-middle ml-1">
                                        Target
                                    </Pill>
                                )}
                            </p>
                            <p className="text-xs mono" style={{ color: 'var(--faint)' }}>
                                {environment.slug}
                            </p>
                        </div>
                    </div>
                </Td>

                {/*
                    Never a blank cell. An environment with no customer is one of exactly
                    two things, and both are said out loud: the platform root, which belongs
                    to no customer by construction, and an unattached leftover, which an
                    operator should be able to SEE is unattached rather than infer from an
                    empty column.
                */}
                <Td>
                    {lineage.organizationHref !== null ? (
                        <>
                            <Link
                                href={lineage.organizationHref}
                                className="font-medium hover:underline"
                            >
                                {lineage.organizationName}
                            </Link>
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.projectName ?? 'No project'}
                            </p>
                        </>
                    ) : lineage.isPlatformRoot ? (
                        <>
                            <Pill>Platform root</Pill>
                            {/*
                                Said in the cell, not in a `title` tooltip as this column
                                used to: a tooltip is unreachable by touch and gone the
                                moment the pointer moves, and this is the sentence that
                                stops a blank-looking owner reading as a broken join.
                            */}
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ?? 'This deployment\u2019s own plane'}
                            </p>
                        </>
                    ) : (
                        <>
                            <Pill tone="warning">Unattached</Pill>
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ?? 'No project, so no customer'}
                            </p>
                        </>
                    )}
                </Td>

                <Td style={{ color: 'var(--muted)' }}>
                    {environment.domain ?? 'None — served on the fallback host'}
                </Td>
                <Td className="text-right tabular-nums">{environment.orgs}</Td>
                <Td className="text-right tabular-nums">{environment.users}</Td>

                <Td className="text-right whitespace-nowrap">
                    <Button
                        size="sm"
                        onClick={onProvision}
                        aria-expanded={provisioning}
                        aria-label={`Provision admin in ${environment.name}`}
                    >
                        Provision admin
                    </Button>
                    {isTarget ? (
                        <span className="text-xs ml-2" style={{ color: 'var(--faint)' }}>
                            Target
                        </span>
                    ) : (
                        <Button
                            size="sm"
                            className="ml-2"
                            onClick={onTarget}
                            aria-label={`Point this console at ${environment.name}`}
                        >
                            Target
                        </Button>
                    )}
                </Td>
            </tr>

            {provisioning && (
                <tr>
                    <Td colSpan={6} style={{ background: 'var(--surface-2)' }}>
                        <ProvisionAdmin environment={environment} onDone={onProvisionDone} />
                    </Td>
                </tr>
            )}
        </>
    );
}

function CreateEnvironment({ href, onDone }: { href: string; onDone: () => void }) {
    const form = useForm({ name: '', domain: '' });
    const name = useRef<HTMLInputElement>(null);

    // The form is a disclosure the operator just opened, so focus moves into it — the
    // control that opened it is now behind a panel that was not there a moment ago. Done
    // on mount rather than with `autofocus`, which fires on page LOAD and would drag a
    // screen reader past the heading of a page nobody asked to write on.
    useEffect(() => {
        name.current?.focus();
    }, []);

    return (
        <form
            className="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="flex-1" style={{ minWidth: '14rem' }}>
                <Field label="Name" error={form.errors.name}>
                    <Input
                        ref={name}
                        name="name"
                        placeholder="Production"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '14rem' }}>
                <Field
                    label="Custom domain (optional)"
                    hint="Recorded, not routed: the plane serves its own issuer until the domain is verified by DNS."
                    error={form.errors.domain}
                >
                    <Input
                        name="domain"
                        placeholder="id.acme.com"
                        value={form.data.domain}
                        onChange={(event) => form.setData('domain', event.target.value)}
                    />
                </Field>
            </div>

            <Button type="submit" variant="primary" loading={form.processing}>
                Create
            </Button>
            <Button type="button" onClick={onDone}>
                Cancel
            </Button>
        </form>
    );
}

function ProvisionAdmin({
    environment,
    onDone,
}: {
    environment: EnvironmentRow;
    onDone: () => void;
}) {
    const form = useForm({ orgName: '', adminName: '', adminEmail: '', adminPassword: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(environment.provisionHref, {
                    preserveScroll: true,
                    onSuccess: () => onDone(),
                });
            }}
        >
            <p className="text-sm font-semibold mb-3">
                Bootstrap {environment.name} — first organization &amp; admin
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Organization name" error={form.errors.orgName}>
                    <Input
                        name="orgName"
                        placeholder="Acme Inc"
                        value={form.data.orgName}
                        onChange={(event) => form.setData('orgName', event.target.value)}
                    />
                </Field>

                <Field label="Admin name" error={form.errors.adminName}>
                    <Input
                        name="adminName"
                        placeholder="Ada Lovelace"
                        value={form.data.adminName}
                        onChange={(event) => form.setData('adminName', event.target.value)}
                    />
                </Field>

                <Field label="Admin email" error={form.errors.adminEmail}>
                    <Input
                        name="adminEmail"
                        type="email"
                        placeholder="admin@acme.com"
                        value={form.data.adminEmail}
                        onChange={(event) => form.setData('adminEmail', event.target.value)}
                    />
                </Field>

                {/*
                    The floor this is held to is the TARGET tenant's password policy, not
                    this console's — so the hint says what is always true and the server
                    reports the plane's own rule against this field.
                */}
                <Field
                    label="Admin password"
                    hint="Checked against the target environment's own password policy."
                    error={form.errors.adminPassword}
                >
                    <Input
                        name="adminPassword"
                        type="password"
                        autoComplete="new-password"
                        placeholder="At least 12 characters"
                        value={form.data.adminPassword}
                        onChange={(event) => form.setData('adminPassword', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-3 flex gap-2">
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    Provision
                </Button>
                <Button type="button" size="sm" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

Environments.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
