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
    /** The platform root's own environment console, on this host; null for every other row. */
    consoleHref: string | null;
}

type Props = PageProps<{
    environments: EnvironmentRow[];
    pagination: SimplePaginationState;
    search: string;
    activeId: string | null;
    storeHref: string;
    targetHref: string;
    customersHref: string;
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

export default function Environments({
    environments,
    pagination,
    search,
    activeId,
    storeHref,
    targetHref,
    customersHref,
    help,
}: Props) {
    const { t: tc } = useTranslator();

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
                help={help}
                description={tc('console.every_environment_on_this_install_and_who_ow_78b48199')}
                actions={
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.new_environment_39fe4fa8')}
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_name_slug_or_domain_e09d660c')}
                    aria-label={tc('console.search_environments_58b59caa')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the table is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.count}{' '}
                    {pagination.count === 1
                        ? tc('console.environment_ba528516')
                        : tc('console.environments_d5f6d542')}
                    {tc('console.on_this_page_d8ae7c64')}
                </output>
            </div>

            {creating && <CreateEnvironment href={storeHref} onDone={() => setCreating(false)} />}

            {environments.length === 0 ? (
                <div className="mt-8">
                    {search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={tc('console.no_environments_match_value0_016e4e16', {
                                value0: search,
                            })}
                            description={tc(
                                'console.try_part_of_the_name_the_routing_slug_or_a_c_7c76fa0d',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title={tc('console.no_environments_yet_8c63cac4')}
                            description={tc(
                                'console.an_environment_is_fully_isolated_its_own_use_bc49f6f9',
                            )}
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    {tc('console.new_environment_39fe4fa8')}
                                </Button>
                            }
                        />
                    )}
                </div>
            ) : (
                <>
                    <div className="cbx-panel overflow-hidden mt-8">
                        <div className="overflow-x-auto">
                            <Table
                                caption={tc(
                                    'console.environments_on_this_install_with_their_owne_2d6dd3c5',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{tc('console.environment_9e471951')}</Th>
                                        <Th>{tc('console.belongs_to_5c9c76cb')}</Th>
                                        <Th>{tc('console.domain_79fa3361')}</Th>
                                        <Th className="text-right">
                                            {tc('console.orgs_ff9e49b5')}
                                        </Th>
                                        <Th className="text-right">
                                            {tc('console.users_6b0cc904')}
                                        </Th>
                                        <Th>
                                            <span className="sr-only">
                                                {tc('console.actions_ff8059dc')}
                                            </span>
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
                            noun={tc('console.environment_ba528516')}
                            pluralNoun={tc('console.environments_d5f6d542')}
                            href={(page) => listHref(search, page)}
                        />
                    </div>
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                {tc('console.environments_nest_under_a_project_and_a_proj_71416d24')}{' '}
                <Link href={customersHref} className="underline">
                    {tc('console.workspace_21a3230e')}
                </Link>{' '}
                {tc('console.start_there_to_see_one_workspace_rsquo_s_who_ea9d463c')}
                <strong>{tc('console.platform_root_1289b7b2')}</strong>
                {tc('console.is_the_environment_this_deployment_itself_ru_975cfa3e')}{' '}
                <strong>{tc('console.unattached_fa556749')}</strong>
                {tc('console.environment_has_no_project_so_nothing_bills__199b4e13')}
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
                title={
                    targeting === null
                        ? ''
                        : tc('console.point_this_console_at_value0_f148c7bb', {
                              value0: targeting.name,
                          })
                }
                description={tc('console.every_page_you_open_from_now_on_organization_7ba726d3')}
                footer={
                    <>
                        <Button onClick={() => setTargeting(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {tc('console.target_978354db')}
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
    const { t: tc } = useTranslator();

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
                                        {tc('console.target_978354db')}
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
                                {lineage.projectName ?? tc('console.no_project_f34c2be0')}
                            </p>
                        </>
                    ) : lineage.isPlatformRoot ? (
                        <>
                            <Pill>{tc('console.platform_root_7ee14f2f')}</Pill>
                            {/*
                                Said in the cell, not in a `title` tooltip as this column
                                used to: a tooltip is unreachable by touch and gone the
                                moment the pointer moves, and this is the sentence that
                                stops a blank-looking owner reading as a broken join.
                            */}
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ??
                                    tc('console.this_deployment_s_own_environment_6e61c77d')}
                            </p>
                        </>
                    ) : (
                        <>
                            <Pill tone="warning">{tc('console.unattached_43c83268')}</Pill>
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ?? tc('console.no_project_so_no_workspace_305f3951')}
                            </p>
                        </>
                    )}
                </Td>

                <Td style={{ color: 'var(--muted)' }}>
                    {environment.domain ?? tc('console.none_served_on_the_fallback_host_7c84367e')}
                </Td>
                <Td className="text-right tabular-nums">{environment.orgs}</Td>
                <Td className="text-right tabular-nums">{environment.users}</Td>

                <Td className="text-right whitespace-nowrap">
                    {environment.consoleHref !== null && (
                        <a
                            href={environment.consoleHref}
                            className="text-sm font-medium hover:underline mr-3"
                            aria-label={tc(
                                'console.open_the_console_of_value0_the_platform_s_ow_4a3495b0',
                                { value0: environment.name },
                            )}
                        >
                            {tc('console.open_console_f4063d1b')}
                        </a>
                    )}
                    <Button
                        size="sm"
                        onClick={onProvision}
                        aria-expanded={provisioning}
                        aria-label={tc('console.provision_admin_in_value0_abf89791', {
                            value0: environment.name,
                        })}
                    >
                        {tc('console.provision_admin_72aea71a')}
                    </Button>
                    {isTarget ? (
                        <span className="text-xs ml-2" style={{ color: 'var(--faint)' }}>
                            {tc('console.target_978354db')}
                        </span>
                    ) : (
                        <Button
                            size="sm"
                            className="ml-2"
                            onClick={onTarget}
                            aria-label={tc('console.point_this_console_at_value0_7211e979', {
                                value0: environment.name,
                            })}
                        >
                            {tc('console.target_978354db')}
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
    const { t: tc } = useTranslator();

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
                <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        ref={name}
                        name="name"
                        placeholder={tc('console.production_13a7c655')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '14rem' }}>
                <Field
                    label={tc('console.custom_domain_optional_2e942026')}
                    hint={tc('console.recorded_not_routed_the_environment_serves_i_1e644612')}
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
                {tc('console.create_4759498a')}
            </Button>
            <Button type="button" onClick={onDone}>
                {tc('console.cancel_19766ed6')}
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
    const { t: tc } = useTranslator();

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
                {tc('console.bootstrap_493af033')}
                {environment.name}
                {tc('console.first_organization_amp_admin_59899cc7')}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={tc('console.organization_name_9a807d52')} error={form.errors.orgName}>
                    <Input
                        name="orgName"
                        placeholder={tc('console.acme_inc_42cf7a26')}
                        value={form.data.orgName}
                        onChange={(event) => form.setData('orgName', event.target.value)}
                    />
                </Field>

                <Field label={tc('console.admin_name_78402a23')} error={form.errors.adminName}>
                    <Input
                        name="adminName"
                        placeholder={tc('console.ada_lovelace_76740216')}
                        value={form.data.adminName}
                        onChange={(event) => form.setData('adminName', event.target.value)}
                    />
                </Field>

                <Field label={tc('console.admin_email_f7d29082')} error={form.errors.adminEmail}>
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
                    label={tc('console.admin_password_f1514ea5')}
                    hint={tc('console.checked_against_the_target_environment_s_own_6a4d22da')}
                    error={form.errors.adminPassword}
                >
                    <Input
                        name="adminPassword"
                        type="password"
                        autoComplete="new-password"
                        placeholder={tc('console.at_least_12_characters_0ced89a6')}
                        value={form.data.adminPassword}
                        onChange={(event) => form.setData('adminPassword', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-3 flex gap-2">
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    {tc('console.provision_41112adc')}
                </Button>
                <Button type="button" size="sm" onClick={onDone}>
                    {tc('console.cancel_19766ed6')}
                </Button>
            </div>
        </form>
    );
}

Environments.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
