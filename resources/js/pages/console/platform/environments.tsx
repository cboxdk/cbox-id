import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                description={consoleT(
                    'console.every_environment_on_this_install_and_who_owns_it_creat_78b48199',
                )}
                actions={
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        {consoleRich('console._part1_new_environment_3f6f97e1', {
                            part1: <Icon name="plus" className="w-4 h-4" />,
                        })}
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_name_slug_or_domain_e09d660c')}
                    aria-label={consoleT('console.search_environments_58b59caa')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the table is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {consoleRich('console.results_on_page', { count: pagination.count })}
                </output>
            </div>

            {creating && <CreateEnvironment href={storeHref} onDone={() => setCreating(false)} />}

            {environments.length === 0 ? (
                <div className="mt-8">
                    {search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleFinalText(
                                'console.no_environments_match_part1_fdc05598',
                                { part1: search },
                            )}
                            description={consoleT(
                                'console.try_part_of_the_name_the_routing_slug_or_a_custom_domai_7c76fa0d',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="layers"
                            title={consoleT('console.no_environments_yet_8c63cac4')}
                            description={consoleT(
                                'console.an_environment_is_fully_isolated_its_own_users_keys_sig_bc49f6f9',
                            )}
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    {consoleRich('console._part1_new_environment_3f6f97e1', {
                                        part1: <Icon name="plus" className="w-4 h-4" />,
                                    })}
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
                                caption={consoleUiText(
                                    'console.environments_on_this_install_with_their_owner_custom_do_2d6dd3c5',
                                )}
                            >
                                <thead>
                                    <tr>
                                        <Th>{consoleT('console.environment_9e471951')}</Th>
                                        <Th>{consoleT('console.belongs_to_5c9c76cb')}</Th>
                                        <Th>{consoleT('console.domain_79fa3361')}</Th>
                                        <Th className="text-right">
                                            {consoleT('console.orgs_ff9e49b5')}
                                        </Th>
                                        <Th className="text-right">
                                            {consoleT('console.users_6b0cc904')}
                                        </Th>
                                        <Th>
                                            <span className="sr-only">
                                                {consoleT('console.actions_ff8059dc')}
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
                            href={(page) => listHref(search, page)}
                        />
                    </div>
                </>
            )}

            <p className="mt-6 text-xs" style={{ color: 'var(--faint)' }}>
                {consoleRich(
                    'console.environments_nest_under_a_project_and_a_project_under_a_03ba63b6',
                    {
                        part1: ' ',
                        part2: (
                            <Link href={customersHref} className="underline">
                                {consoleT('console.workspace_21a3230e')}
                            </Link>
                        ),
                        part3: ' ',
                        part4: <strong>{consoleT('console.platform_root_1289b7b2')}</strong>,
                        part5: ' ',
                        part6: <strong>{consoleT('console.unattached_fa556749')}</strong>,
                    },
                )}
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
                        : consoleFinalText('console.point_this_console_at_part1_6614bd12', {
                              part1: targeting.name,
                          })
                }
                description={consoleT(
                    'console.every_page_you_open_from_now_on_organizations_usage_org_7ba726d3',
                )}
                footer={
                    <>
                        <Button onClick={() => setTargeting(null)}>
                            {consoleT('console.cancel_19766ed6')}
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
                            {consoleT('console.target_978354db')}
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
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
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
                                        {consoleT('console.target_978354db')}
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
                                {lineage.projectName ??
                                    consoleRuntimeText('console.no_project_f34c2be0')}
                            </p>
                        </>
                    ) : lineage.isPlatformRoot ? (
                        <>
                            <Pill>{consoleT('console.platform_root_7ee14f2f')}</Pill>
                            {/*
                                Said in the cell, not in a `title` tooltip as this column
                                used to: a tooltip is unreachable by touch and gone the
                                moment the pointer moves, and this is the sentence that
                                stops a blank-looking owner reading as a broken join.
                            */}
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ??
                                    consoleRuntimeText(
                                        'console.this_deployment_s_own_environment_6e61c77d',
                                    )}
                            </p>
                        </>
                    ) : (
                        <>
                            <Pill tone="warning">{consoleT('console.unattached_43c83268')}</Pill>
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {lineage.note ??
                                    consoleRuntimeText(
                                        'console.no_project_so_no_workspace_305f3951',
                                    )}
                            </p>
                        </>
                    )}
                </Td>

                <Td style={{ color: 'var(--muted)' }}>
                    {environment.domain ??
                        consoleRuntimeText('console.none_served_on_the_fallback_host_7c84367e')}
                </Td>
                <Td className="text-right tabular-nums">{environment.orgs}</Td>
                <Td className="text-right tabular-nums">{environment.users}</Td>

                <Td className="text-right whitespace-nowrap">
                    {environment.consoleHref !== null && (
                        <a
                            href={environment.consoleHref}
                            className="text-sm font-medium hover:underline mr-3"
                            aria-label={consoleFinalText(
                                'console.open_the_console_of_part1_the_platform_s_own_environmen_37fd9a5c',
                                { part1: environment.name },
                            )}
                        >
                            {consoleT('console.open_console_f4063d1b')}
                        </a>
                    )}
                    <Button
                        size="sm"
                        onClick={onProvision}
                        aria-expanded={provisioning}
                        aria-label={consoleFinalText('console.provision_admin_in_part1_afd97e30', {
                            part1: environment.name,
                        })}
                    >
                        {consoleT('console.provision_admin_72aea71a')}
                    </Button>
                    {isTarget ? (
                        <span className="text-xs ml-2" style={{ color: 'var(--faint)' }}>
                            {consoleT('console.target_978354db')}
                        </span>
                    ) : (
                        <Button
                            size="sm"
                            className="ml-2"
                            onClick={onTarget}
                            aria-label={consoleFinalText(
                                'console.point_this_console_at_part1_a53811c7',
                                { part1: environment.name },
                            )}
                        >
                            {consoleT('console.target_978354db')}
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
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                <Field label={consoleT('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        ref={name}
                        name="name"
                        placeholder={consoleUiText('console.production_13a7c655')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '14rem' }}>
                <Field
                    label={consoleT('console.custom_domain_optional_2e942026')}
                    hint={consoleT(
                        'console.recorded_not_routed_the_environment_serves_its_own_issu_1e644612',
                    )}
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
                {consoleT('console.create_4759498a')}
            </Button>
            <Button type="button" onClick={onDone}>
                {consoleT('console.cancel_19766ed6')}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                {consoleRich('console.bootstrap_part1_first_organization_admin_7459ca65', {
                    part1: environment.name,
                })}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label={consoleT('console.organization_name_9a807d52')}
                    error={form.errors.orgName}
                >
                    <Input
                        name="orgName"
                        placeholder={tc('console.acme_inc_42cf7a26')}
                        value={form.data.orgName}
                        onChange={(event) => form.setData('orgName', event.target.value)}
                    />
                </Field>

                <Field
                    label={consoleT('console.admin_name_78402a23')}
                    error={form.errors.adminName}
                >
                    <Input
                        name="adminName"
                        placeholder={consoleUiText('console.ada_lovelace_76740216')}
                        value={form.data.adminName}
                        onChange={(event) => form.setData('adminName', event.target.value)}
                    />
                </Field>

                <Field
                    label={consoleT('console.admin_email_f7d29082')}
                    error={form.errors.adminEmail}
                >
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
                    label={consoleT('console.admin_password_f1514ea5')}
                    hint={consoleT(
                        'console.checked_against_the_target_environment_s_own_password_p_6a4d22da',
                    )}
                    error={form.errors.adminPassword}
                >
                    <Input
                        name="adminPassword"
                        type="password"
                        autoComplete="new-password"
                        placeholder={consoleUiText('console.at_least_12_characters_0ced89a6')}
                        value={form.data.adminPassword}
                        onChange={(event) => form.setData('adminPassword', event.target.value)}
                    />
                </Field>
            </div>

            <div className="mt-3 flex gap-2">
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    {consoleT('console.provision_41112adc')}
                </Button>
                <Button type="button" size="sm" onClick={onDone}>
                    {consoleT('console.cancel_19766ed6')}
                </Button>
            </div>
        </form>
    );
}

Environments.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
