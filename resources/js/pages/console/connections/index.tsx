import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import type {
    CertificateWarning,
    HelpContent,
    OrganizationFilter,
    PageProps,
    Pagination as PaginationState,
} from '@/types';
import {
    Badge,
    Button,
    CertificateWarnings,
    ConfirmDelete,
    EmptyState,
    Field,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface ConnectionRow {
    id: string;
    name: string;
    type: string;
    active: boolean;
    status: string;
    owner: string | null;
    href: string;
}

interface DomainRow {
    id: string;
    domain: string;
    verified: boolean;
    capture: boolean;
}

type Props = PageProps<{
    help: HelpContent;
    connections: ConnectionRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    entitled: boolean;
    /** The environment-wide list's Organization chip; null on a page about one organization. */
    organizationFilter: OrganizationFilter | null;
    /** An organization's domains — null on the environment-wide list, which is about none. */
    domains: DomainRow[] | null;
    createHref: string;
    /** The writes about ONE organization; null where the page is about none. */
    urls: { invite: string; addDomain: string } | null;
    /** The organization's SAML certificates about to stop working; empty where the page is about none. */
    certificateWarnings: CertificateWarning[];
}>;

export default function ConnectionsIndex({
    help,
    connections,
    pagination,
    search,
    mayAdminister,
    entitled,
    organizationFilter,
    domains,
    createHref,
    urls,
    certificateWarnings,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // Both on the flash channel: the portal link admits its holder to this tenant's SSO
    // setup with no account at all, and the DNS token is a one-shot instruction. Neither
    // belongs in a history entry.
    const { portalUrl, dns } = usePage().flash;

    const [term, setTerm] = useState(search);
    const [removing, setRemoving] = useState<DomainRow | null>(null);

    const domainForm = useForm({ domain: '' });

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

    const canAct = mayAdminister && entitled;

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.let_people_sign_in_with_the_company_account_they_alread_b98aff03',
                )}
                actions={
                    canAct ? (
                        <>
                            {urls !== null && (
                                <Button
                                    icon="members"
                                    onClick={() =>
                                        router.post(urls.invite, {}, { preserveScroll: true })
                                    }
                                >
                                    {consoleT('console.invite_your_it_admin_3aba7671')}
                                </Button>
                            )}
                            <Button asChild variant="primary" className="shrink-0">
                                <Link href={createHref}>
                                    {consoleRich('console._part1_new_connection_bce987f9', {
                                        part1: <Icon name="plus" className="w-4 h-4" />,
                                    })}
                                </Link>
                            </Button>
                        </>
                    ) : undefined
                }
            />

            {certificateWarnings.length > 0 && (
                <div className="mb-6">
                    <CertificateWarnings warnings={certificateWarnings} />
                </div>
            )}

            {!entitled ? (
                <div className="card mt-8">
                    <EmptyState
                        icon="connections"
                        title={consoleT('console.single_sign_on_is_an_enterprise_feature_26381303')}
                        help={help}
                        description={consoleT(
                            'console.letting_your_people_sign_in_with_entra_id_okta_or_googl_10c80076',
                        )}
                    />
                </div>
            ) : (
                <div className="mt-8 space-y-6">
                    {portalUrl !== undefined && mayAdminister && (
                        <RevealedOnce
                            icon="members"
                            title={consoleT('console.setup_link_for_your_it_admin_df0a3a89')}
                            description={consoleT(
                                'console.send_this_single_use_link_to_whoever_configures_your_id_e8056999',
                            )}
                            value={portalUrl}
                        />
                    )}

                    <div className="space-y-3">
                        {organizationFilter !== null && (
                            <FilterChips>
                                <OrganizationFilterChip filter={organizationFilter} />
                            </FilterChips>
                        )}
                        <Input
                            type="search"
                            style={{ maxWidth: '24rem' }}
                            placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                            aria-label={consoleT('console.search_connections_b8826956')}
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                        />
                        {/*
                                SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                                change, so the count is the only thing that can report the filter
                                narrowed to nothing.
                            */}
                        <output className="sr-only">
                            {consoleRich('console.search_results_found', {
                                count: pagination.total,
                            })}
                        </output>
                    </div>

                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {connections.length === 0 ? (
                            search !== '' ? (
                                <EmptyState
                                    icon="search"
                                    title={consoleFinalText(
                                        'console.no_matches_for_part1_42a992b5',
                                        { part1: search },
                                    )}
                                    description={consoleT(
                                        'console.no_connections_match_that_name_try_a_different_search_t_6555c50e',
                                    )}
                                />
                            ) : (
                                <EmptyState
                                    icon="connections"
                                    equivalent="sso.connections.create"
                                    title={consoleT(
                                        'console.no_identity_provider_connected_yet_91d235cb',
                                    )}
                                    help={help}
                                    description={consoleT(
                                        'console.right_now_people_sign_in_with_credentials_held_here_con_4538c692',
                                    )}
                                    steps={[
                                        consoleRuntimeText(
                                            'console.in_entra_id_okta_or_google_workspace_create_an_applicat_bd295fcb',
                                        ),
                                        consoleRuntimeText(
                                            'console.add_the_connection_paste_the_provider_s_metadata_and_th_c0c33b44',
                                        ),
                                        consoleRuntimeText(
                                            'console.verify_the_email_domains_you_own_so_your_people_are_rou_3f6d7cfe',
                                        ),
                                        consoleRuntimeText(
                                            'console.activate_the_connection_once_a_test_sign_in_works_40db9d00',
                                        ),
                                    ]}
                                    actions={
                                        canAct ? (
                                            <Button asChild variant="primary">
                                                <Link href={createHref}>
                                                    {consoleRich(
                                                        'console._part1_new_connection_bce987f9',
                                                        {
                                                            part1: (
                                                                <Icon
                                                                    name="plus"
                                                                    className="w-4 h-4"
                                                                />
                                                            ),
                                                        },
                                                    )}
                                                </Link>
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )
                        ) : (
                            connections.map((connection, index) => (
                                <Link
                                    key={connection.id}
                                    href={connection.href}
                                    className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                                    style={
                                        index < connections.length - 1
                                            ? { borderBottom: '1px solid var(--border)' }
                                            : undefined
                                    }
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="font-medium truncate">
                                                {connection.name}
                                            </span>
                                            {organizationFilter !== null &&
                                                connection.owner !== null && (
                                                    <Badge>{connection.owner}</Badge>
                                                )}
                                        </div>
                                        <p
                                            className="text-xs truncate mono"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {connection.id}
                                        </p>
                                    </div>
                                    <Pill tone="info">{connection.type}</Pill>
                                    <Badge tone={connection.active ? 'success' : 'neutral'}>
                                        {connection.active
                                            ? consoleText('console.active_92340695')
                                            : connection.status}
                                    </Badge>
                                    <Icon
                                        name="chevron"
                                        className="w-4 h-4 shrink-0"
                                        style={{
                                            color: 'var(--faint)',
                                            transform: 'rotate(-90deg)',
                                        }}
                                    />
                                </Link>
                            ))
                        )}
                    </div>

                    <Pagination pagination={pagination} href={(page) => listHref(search, page)} />

                    {/*
                        Verified domains — DNS-proven ownership powers home-realm discovery
                        and the optional capture gate. They belong to the ORGANIZATION
                        rather than to one connection, which is why they live here.
                    */}
                    {domains !== null && urls !== null && (
                        <div className="space-y-4">
                            <div>
                                <h2 className="cbx-panel-title" style={{ fontSize: '18px' }}>
                                    {consoleT('console.verified_domains_b01c494f')}
                                </h2>
                                <p
                                    className="mt-1 text-sm"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {consoleT(
                                        'console.prove_ownership_of_an_email_domain_to_route_your_team_t_bc84ec01',
                                    )}
                                </p>
                            </div>

                            {mayAdminister && (
                                <form
                                    className="card p-5 flex flex-wrap items-end gap-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        domainForm.post(urls.addDomain, {
                                            preserveScroll: true,
                                            onSuccess: () => domainForm.reset(),
                                        });
                                    }}
                                >
                                    <div className="flex-1 min-w-[14rem]">
                                        <Field
                                            label={consoleT('console.domain_79fa3361')}
                                            error={domainForm.errors.domain}
                                        >
                                            <Input
                                                name="domain"
                                                className="mono"
                                                inputMode="url"
                                                autoCapitalize="none"
                                                spellCheck={false}
                                                placeholder="acme.com"
                                                value={domainForm.data.domain}
                                                onChange={(event) =>
                                                    domainForm.setData('domain', event.target.value)
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        icon="plus"
                                        loading={domainForm.processing}
                                    >
                                        {consoleT('console.add_domain_d86476ae')}
                                    </Button>
                                </form>
                            )}

                            {dns !== undefined && mayAdminister && (
                                <RevealedOnce
                                    icon="connections"
                                    title={consoleFinalText('console.verify_part1_5e0158de', {
                                        part1: dns.domain,
                                    })}
                                    description={
                                        <>
                                            {consoleRich(
                                                'console.add_a_txt_record_at_part1_part2_with_the_value_below_th_455edc17',
                                                {
                                                    part1: ' ',
                                                    part2: <code className="mono">{dns.host}</code>,
                                                },
                                            )}
                                        </>
                                    }
                                    value={dns.token}
                                />
                            )}

                            <div className="space-y-3">
                                {domains.length === 0 ? (
                                    <div className="card">
                                        <EmptyState
                                            icon="directory"
                                            title={consoleT(
                                                'console.no_verified_domains_yet_77625b2a',
                                            )}
                                            description={consoleT(
                                                'console.verifying_a_domain_you_own_acme_com_lets_cbox_id_recogn_7a9ae408',
                                            )}
                                        />
                                    </div>
                                ) : (
                                    domains.map((domain) => (
                                        <DomainCard
                                            key={domain.id}
                                            domain={domain}
                                            mayAdminister={mayAdminister}
                                            onRemove={() => setRemoving(domain)}
                                        />
                                    ))
                                )}
                            </div>
                        </div>
                    )}
                </div>
            )}

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                consequence={consoleUiText(
                    'console.this_domain_will_no_longer_route_its_users_through_this_281a9227',
                )}
                onConfirm={() => {
                    const target = removing;
                    setRemoving(null);

                    if (target !== null) {
                        router.delete(`${window.location.pathname}/domains/${target.id}`, {
                            preserveScroll: true,
                        });
                    }
                }}
            />
        </>
    );
}

function DomainCard({
    domain,
    mayAdminister,
    onRemove,
}: {
    domain: DomainRow;
    mayAdminister: boolean;
    onRemove: () => void;
}) {
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const base = `${window.location.pathname}/domains/${domain.id}`;

    return (
        <div className="card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-semibold truncate mono">{domain.domain}</p>
                        <Pill tone={domain.verified ? 'success' : 'warning'}>
                            {domain.verified
                                ? consoleText('console.verified_4f783840')
                                : consoleText('console.pending_331551b0')}
                        </Pill>
                        {domain.capture && (
                            <Pill tone="info">{consoleT('console.capture_on_f676810d')}</Pill>
                        )}
                    </div>
                </div>

                {mayAdminister && (
                    <div className="flex items-center gap-2">
                        {!domain.verified && (
                            <Button
                                variant="primary"
                                size="sm"
                                icon="check"
                                onClick={() =>
                                    router.post(`${base}/verify`, {}, { preserveScroll: true })
                                }
                            >
                                {consoleT('console.verify_eea2745e')}
                            </Button>
                        )}
                        <Button size="sm" variant="danger" onClick={onRemove}>
                            {consoleT('console.remove_c3812fc4')}
                        </Button>
                    </div>
                )}
            </div>

            {domain.verified && mayAdminister && (
                <div
                    className="mt-4 flex items-start justify-between gap-3 rounded-lg px-3 py-3"
                    style={{ background: 'var(--secondary)' }}
                >
                    <div className="min-w-0">
                        <p className="text-sm font-medium">
                            {consoleT('console.capture_1900b478')}
                        </p>
                        <p className="mt-0.5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleRich(
                                'console.force_everyone_with_an_part1_email_to_sign_in_through_t_63f0ff4f',
                                { part1: domain.domain },
                            )}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant={domain.capture ? 'primary' : 'ghost'}
                        onClick={() => router.post(`${base}/capture`, {}, { preserveScroll: true })}
                    >
                        {domain.capture
                            ? consoleText('console.on_13001175')
                            : consoleText('console.off_ca7981b4')}
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * A value the server will not repeat.
 *
 * Both of the things this page mints are one-shot: the portal link is a credential in a
 * URL, and the DNS token is the answer to a challenge that is re-issued rather than
 * re-read. Neither is a page prop, so neither is in the history entry — this is where the
 * one render that carries them shows them.
 */
function RevealedOnce({
    icon,
    title,
    description,
    value,
}: {
    icon: 'members' | 'connections';
    title: React.ReactNode;
    description: React.ReactNode;
    value: string;
}) {
    return (
        <Panel
            className="p-5"
            style={{ borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)' }}
        >
            <div className="flex items-center gap-2 font-semibold">
                <Icon name={icon} className="w-4 h-4" />
                {title}
            </div>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {description}
            </p>
            <p
                className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
            >
                {value}
            </p>
        </Panel>
    );
}

ConnectionsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
