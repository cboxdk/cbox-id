import { useConsoleCount } from '@/i18n/count';
import { useTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, SimplePagination as SimplePaginationState } from '@/types';
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
    help: HelpContent;
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

export default function Customers({
    customers,
    pagination,
    search,
    storeHref,
    environmentsHref,
    environmentLimits,
    help,
}: Props) {
    const { t: tc } = useTranslator();
    const plural = useConsoleCount();

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
                help={help}
                description={tc('console.every_workspace_on_this_install_open_one_to__4abbea51')}
                actions={
                    <Button
                        variant="primary"
                        aria-expanded={creating}
                        onClick={() => setCreating((open) => !open)}
                    >
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.new_workspace_df0caf1b')}
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
                    placeholder={tc('console.search_by_name_or_slug_bfd8464a')}
                    aria-label={tc('console.search_workspaces_c0cd6bdf')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                <output className="sr-only">
                    {plural(pagination.count, 'workspace')}
                    {tc('console.on_this_page_d8ae7c64')}
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
                            title={tc('console.no_workspaces_match_value0_be4602eb', {
                                value0: search,
                            })}
                            description={tc(
                                'console.try_part_of_the_organization_name_or_its_slu_51fd9569',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="settings"
                            title={tc('console.no_workspaces_yet_97d0b117')}
                            description={tc(
                                'console.a_workspace_appears_here_the_moment_somebody_ce907423',
                            )}
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    {tc('console.new_workspace_df0caf1b')}
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
                            <Table
                                caption={tc(
                                    'console.workspaces_on_this_install_with_the_size_of__23b98cfe',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{tc('console.workspace_87bb59ba')}</Th>
                                        <Th className="text-right">
                                            {tc('console.members_1044a4c0')}
                                        </Th>
                                        <Th className="text-right">
                                            {tc('console.projects_04e2a972')}
                                        </Th>
                                        <Th className="text-right">
                                            {tc('console.environments_07437cd6')}
                                        </Th>
                                        <Th>{tc('console.created_d70b9e24')}</Th>
                                        <Th>
                                            <span className="sr-only">
                                                {tc('console.actions_ff8059dc')}
                                            </span>
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
                                                            {tc('console.suspended_e392a389')}
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
                                                label={tc('console.value0_on_value1_37b189db', {
                                                    value0: plural(customer.members, 'member'),
                                                    value1: customer.name,
                                                })}
                                            />
                                            <CountCell
                                                href={customer.href}
                                                count={customer.projects}
                                                label={tc('console.value0_on_value1_37b189db', {
                                                    value0: plural(customer.projects, 'project'),
                                                    value1: customer.name,
                                                })}
                                            />
                                            <CountCell
                                                href={customer.href}
                                                count={customer.environments}
                                                label={tc('console.value0_on_value1_37b189db', {
                                                    value0: plural(
                                                        customer.environments,
                                                        'environment',
                                                    ),
                                                    value1: customer.name,
                                                })}
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
                                                        aria-label={tc(
                                                            'console.open_value0_f5734efb',
                                                            { value0: customer.name },
                                                        )}
                                                    >
                                                        {tc('console.open_ed077f3d')}
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    className="ml-2"
                                                    variant={customer.active ? 'danger' : 'primary'}
                                                    // Named for the row: see the same
                                                    // control on the Organizations list.
                                                    aria-label={`${customer.active ? 'Suspend' : 'Reactivate'} ${customer.name}`}
                                                    onClick={() => setConfirming(customer)}
                                                >
                                                    {customer.active
                                                        ? tc('console.suspend_4948e134')
                                                        : tc('console.reactivate_2dc7478f')}
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
                            noun={tc('console.workspace_21a3230e')}
                            pluralNoun={tc('console.workspaces_6e8d7998')}
                            href={(page) => listHref(search, page)}
                        />
                    </div>
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                {tc('console.suspension_is_the_only_lever_here_and_it_is__2acf8690')}{' '}
                <Link href={environmentsHref} className="underline">
                    {tc('console.environments_07437cd6')}
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
                          ? tc('console.its_members_are_signed_out_and_all_value0_it_9e9957fc', {
                                value0: plural(confirming.environments, 'environment'),
                            })
                          : tc('console.its_members_can_sign_in_again_and_its_enviro_d369bd33')
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {confirming?.active === true
                                ? tc('console.suspend_4948e134')
                                : tc('console.reactivate_2dc7478f')}
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
    const { t: tc } = useTranslator();

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
        <Panel title={tc('console.new_workspace_df0caf1b')} className="mt-6">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
                }}
            >
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.creates_the_organization_its_owner_its_first_79cca112')}
                </p>

                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Field
                            label={tc('console.workspace_name_9619649d')}
                            error={form.errors.name}
                        >
                            <Input
                                ref={name}
                                name="name"
                                autoComplete="organization"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label={tc('console.owner_name_0c19629c')} error={form.errors.ownerName}>
                        <Input
                            name="ownerName"
                            autoComplete="name"
                            value={form.data.ownerName}
                            onChange={(event) => form.setData('ownerName', event.target.value)}
                        />
                    </Field>

                    <Field
                        label={tc('console.owner_email_cbc7ec6d')}
                        hint={tc('console.if_this_address_already_holds_a_cbox_id_it_i_88a57e1d')}
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
                        label={tc('console.environment_allowance_f277ec0f')}
                        hint={tc('console.the_plan_allowance_on_their_first_product_bi_982af408')}
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
                        {tc('console.create_workspace_4b892277')}
                    </Button>
                    <Button type="button" onClick={onDone}>
                        {tc('console.cancel_19766ed6')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

Customers.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
