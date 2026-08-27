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
    Panel,
    Pill,
    Select,
    SimplePagination,
    Table,
    Td,
    Th,
} from '@/ui';

interface CustomerRow {
    id: string;
    name: string;
    active: boolean;
    members: number;
    projects: number;
    environments: number;
    createdAt: string | null;
    href: string;
    toggleHref: string;
}

type Props = PageProps<{
    customers: CustomerRow[];
    pagination: SimplePaginationState;
    search: string;
    storeHref: string;
    environmentsHref: string;
    environmentLimits: number[];
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

function plural(count: number, noun: string): string {
    return `${count} ${count === 1 ? noun : `${noun}s`}`;
}

export default function Customers({
    customers,
    pagination,
    search,
    storeHref,
    environmentsHref,
    environmentLimits,
}: Props) {
    const [term, setTerm] = useState(search);
    const [creating, setCreating] = useState(false);
    const [confirming, setConfirming] = useState<CustomerRow | null>(null);

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
                description="Every customer on this install. Open one to walk its products and environments; suspending it signs out its members and stops every environment it owns from serving auth."
                actions={
                    <Button
                        variant="primary"
                        aria-expanded={creating}
                        onClick={() => setCreating((open) => !open)}
                    >
                        <Icon name="plus" className="w-4 h-4" />
                        New customer
                    </Button>
                }
            />

            {/*
                Onboarding a customer who did not self-serve. This page could suspend a
                customer, walk their estate and impersonate their people, and could not
                create one — so the only way to stand one up was to tinker on a production
                console.
            */}
            {creating && (
                <CreateCustomer
                    href={storeHref}
                    limits={environmentLimits}
                    onDone={() => setCreating(false)}
                />
            )}

            {/*
                The search sits above the count, so the live region announces the RESULT of
                what was just typed.
            */}
            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name or slug"
                    aria-label="Search customers"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                <output className="sr-only">
                    {plural(pagination.count, 'customer')} on this page.
                </output>
            </div>

            {/*
                TWO EMPTY STATES, because they mean opposite things. "Nothing here yet"
                tells a new operator the install is working and there is nothing to do;
                shown after a search that missed, it reads as "your customers are gone".
            */}
            {customers.length === 0 ? (
                <div className="mt-8">
                    {search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No customers match “${search}”`}
                            description="Try part of the organization name, or its slug."
                        />
                    ) : (
                        <EmptyState
                            icon="settings"
                            title="No customers yet"
                            description="A customer appears here the moment somebody signs up — or use New customer above to onboard one yourself."
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    New customer
                                </Button>
                            }
                        />
                    )}
                </div>
            ) : (
                <>
                    {/*
                        A real table, not a div grid with matching grid-template-columns.
                        The two rows resolved their `fr` tracks against different content —
                        the header's last cell was an empty span, the body's a button — so
                        by "Created" the data sat 121px left of its own heading.
                    */}
                    <div className="cbx-panel overflow-hidden mt-8">
                        <div className="overflow-x-auto">
                            <Table caption="Customers on this install, with the size of each estate.">
                                <thead>
                                    <tr>
                                        <Th>Customer</Th>
                                        <Th className="text-right">Members</Th>
                                        <Th className="text-right">Projects</Th>
                                        <Th className="text-right">Environments</Th>
                                        <Th>Created</Th>
                                        <Th>
                                            <span className="sr-only">Actions</span>
                                        </Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customers.map((customer) => (
                                        <tr key={customer.id}>
                                            <Td>
                                                <p className="font-semibold">
                                                    <Link
                                                        href={customer.href}
                                                        className="hover:underline"
                                                    >
                                                        {customer.name}
                                                    </Link>
                                                    {!customer.active && (
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
                                                    {customer.id}
                                                </p>
                                            </Td>

                                            {/*
                                                A count you cannot click is a fact you have
                                                to go and look for somewhere else. Each is
                                                the same destination with an accessible name
                                                that says which part of it you are asking
                                                about — "3" repeated three times is what a
                                                screen reader would otherwise announce.
                                            */}
                                            <CountCell
                                                href={customer.href}
                                                count={customer.members}
                                                label={`${plural(customer.members, 'member')} on ${customer.name}`}
                                            />
                                            <CountCell
                                                href={customer.href}
                                                count={customer.projects}
                                                label={`${plural(customer.projects, 'project')} on ${customer.name}`}
                                            />
                                            <CountCell
                                                href={customer.href}
                                                count={customer.environments}
                                                label={`${plural(customer.environments, 'environment')} on ${customer.name}`}
                                            />

                                            <Td
                                                className="whitespace-nowrap text-xs"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                {customer.createdAt ?? '—'}
                                            </Td>

                                            <Td className="text-right whitespace-nowrap">
                                                <Button asChild size="sm">
                                                    <Link
                                                        href={customer.href}
                                                        aria-label={`Open ${customer.name}`}
                                                    >
                                                        Open
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    className="ml-2"
                                                    variant={
                                                        customer.active ? 'danger' : 'primary'
                                                    }
                                                    // Named for the row: see the same
                                                    // control on the Organizations list.
                                                    aria-label={`${customer.active ? 'Suspend' : 'Reactivate'} ${customer.name}`}
                                                    onClick={() => setConfirming(customer)}
                                                >
                                                    {customer.active
                                                        ? 'Suspend'
                                                        : 'Reactivate'}
                                                </Button>
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>

                    <div className="mt-4">
                        <SimplePagination
                            pagination={pagination}
                            noun="customer"
                            href={(page) => listHref(search, page)}
                        />
                    </div>
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                Suspension is the only lever here, and it is reversible. Nothing on this screen
                deletes or purges a customer. An install also holds environments that no customer
                owns — the platform root, and any unattached leftover; both are named for what they
                are on{' '}
                <Link href={environmentsHref} className="underline">
                    Environments
                </Link>
                .
            </p>

            {/*
                A reversible two-way switch, so a plain dialog rather than the type-to-confirm
                one — that is for actions with no way back. The copy still names the customer
                and its blast radius.
            */}
            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={
                    confirming === null
                        ? ''
                        : `${confirming.active ? 'Suspend' : 'Reactivate'} ${confirming.name}?`
                }
                description={
                    confirming === null
                        ? ''
                        : confirming.active
                          ? `Its members are signed out and all ${plural(confirming.environments, 'environment')} it owns stop serving auth on the next request. You can reactivate it here.`
                          : 'Its members can sign in again and its environments resume serving auth.'
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
                        <Button
                            variant={confirming?.active === true ? 'danger' : 'primary'}
                            onClick={() => {
                                const customer = confirming;
                                setConfirming(null);

                                if (customer !== null) {
                                    router.post(customer.toggleHref, {}, { preserveScroll: true });
                                }
                            }}
                        >
                            {confirming?.active === true ? 'Suspend' : 'Reactivate'}
                        </Button>
                    </>
                }
            />
        </>
    );
}

function CountCell({ href, count, label }: { href: string; count: number; label: string }) {
    return (
        <Td className="text-right tabular-nums">
            <Link href={href} className="hover:underline" aria-label={label}>
                {count}
            </Link>
        </Td>
    );
}

function CreateCustomer({
    href,
    limits,
    onDone,
}: {
    href: string;
    limits: number[];
    onDone: () => void;
}) {
    const form = useForm({
        name: '',
        ownerName: '',
        ownerEmail: '',
        environmentLimit: limits.includes(2) ? 2 : (limits[0] ?? 1),
    });
    const name = useRef<HTMLInputElement>(null);

    // The panel is a disclosure the operator just opened, so focus moves into it.
    useEffect(() => {
        name.current?.focus();
    }, []);

    return (
        <Panel title="New customer" className="mt-6">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
                }}
            >
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Creates the organization, its owner, its first product and that product&rsquo;s
                    production environment. The owner is emailed a link to set their own password —
                    you do not choose one for them.
                </p>

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Field label="Customer name" error={form.errors.name}>
                            <Input
                                ref={name}
                                name="name"
                                autoComplete="organization"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="Owner name" error={form.errors.ownerName}>
                        <Input
                            name="ownerName"
                            autoComplete="name"
                            value={form.data.ownerName}
                            onChange={(event) => form.setData('ownerName', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="Owner email"
                        hint="If this address already holds a Cbox ID, it is reused — they will own both."
                        error={form.errors.ownerEmail}
                    >
                        <Input
                            name="ownerEmail"
                            type="email"
                            autoComplete="email"
                            value={form.data.ownerEmail}
                            onChange={(event) => form.setData('ownerEmail', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="Environment allowance"
                        hint="The plan allowance on their first product. Billing hangs off the product, not the customer."
                        error={form.errors.environmentLimit}
                    >
                        <Select
                            value={String(form.data.environmentLimit)}
                            onValueChange={(value) =>
                                form.setData('environmentLimit', Number(value))
                            }
                            options={limits.map((limit) => ({
                                value: String(limit),
                                label: String(limit),
                            }))}
                        />
                    </Field>
                </div>

                <div className="mt-6 flex items-center gap-3">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create customer
                    </Button>
                    <Button type="button" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

Customers.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
