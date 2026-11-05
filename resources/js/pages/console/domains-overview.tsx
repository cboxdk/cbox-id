import { useTranslator } from '@/i18n';
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
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Pill,
} from '@/ui';

interface DomainRow {
    id: string;
    domain: string;
    verified: boolean;
    capture: boolean;
    organization: string;
    /** Its organization's Domains tab, where it is verified, captured or removed. */
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    domains: DomainRow[];
    pagination: PaginationState;
    search: string;
    organizationFilter: OrganizationFilter | null;
    organizationsHref: string;
    ssoHref: string;
}>;

/**
 * AUTHENTICATION › DOMAINS — every organization's email domains in one list, each linking to
 * the organization's own Domains tab, which is where one is verified, captured or removed.
 */
export default function DomainsOverview({
    help,
    domains,
    pagination,
    search,
    organizationFilter,
    organizationsHref,
    ssoHref,
}: Props) {
    const { t: tc } = useTranslator();

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
                description={
                    <>
                        {tc('console.the_email_domains_your_organizations_have_cl_5dee8f00')}{' '}
                        <Link href={ssoHref}>{tc('console.enterprise_sso_0f8d7e8e')}</Link>
                        {tc('console.each_one_is_verified_on_its_organization_s_p_11878ff6')}
                    </>
                }
            />

            <div className="mt-8 space-y-6">
                <div className="space-y-3">
                    {organizationFilter !== null && (
                        <FilterChips>
                            <OrganizationFilterChip filter={organizationFilter} />
                        </FilterChips>
                    )}
                    <Input
                        type="search"
                        style={{ maxWidth: '24rem' }}
                        placeholder={tc('console.search_by_domain_8457a6d8')}
                        aria-label={tc('console.search_domains_df3b2794')}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    <output className="sr-only">
                        {pagination.total}{' '}
                        {pagination.total === 1
                            ? tc('console.domain_f2ff8386')
                            : tc('console.domains_1b0ec195')}
                        {tc('console.found_599e25a3')}
                    </output>
                </div>

                <div
                    className="rounded-xl border overflow-hidden"
                    style={{ borderColor: 'var(--border)' }}
                >
                    {domains.length === 0 ? (
                        search !== '' ? (
                            <EmptyState
                                icon="search"
                                title={tc('console.no_matches_for_value0_617e79ef', {
                                    value0: search,
                                })}
                                description={tc(
                                    'console.no_claimed_domain_matches_that_try_part_of_t_1212eb45',
                                )}
                            />
                        ) : (
                            <EmptyState
                                icon="building"
                                title={tc(
                                    'console.no_organization_has_claimed_a_domain_yet_707e5ab8',
                                )}
                                description={tc(
                                    'console.a_domain_is_claimed_by_one_organization_and__85c4b058',
                                )}
                                steps={[
                                    tc(
                                        'console.open_the_organization_and_its_domains_tab_185d9406',
                                    ),
                                    tc(
                                        'console.add_the_domain_and_publish_the_txt_record_it_ddb14174',
                                    ),
                                    tc(
                                        'console.verify_it_then_turn_on_capture_to_route_ever_f88a6ce1',
                                    ),
                                ]}
                                actions={
                                    <Link href={organizationsHref} className="btn btn-primary">
                                        {tc('console.open_organizations_db4241ab')}
                                    </Link>
                                }
                            />
                        )
                    ) : (
                        domains.map((domain, index) => (
                            <Link
                                key={domain.id}
                                href={domain.href}
                                className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                                style={
                                    index < domains.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    <span className="font-medium mono truncate block">
                                        {domain.domain}
                                    </span>
                                    <span
                                        className="text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {domain.organization}
                                    </span>
                                </div>
                                <Pill tone={domain.verified ? 'success' : 'warning'}>
                                    {domain.verified
                                        ? tc('console.verified_4f783840')
                                        : tc('console.waiting_for_dns_38500dff')}
                                </Pill>
                                {domain.capture && (
                                    <Badge tone="info">{tc('console.capture_on_f676810d')}</Badge>
                                )}
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                                />
                            </Link>
                        ))
                    )}
                </div>

                <Pagination
                    pagination={pagination}
                    noun={tc('console.domain_f2ff8386')}
                    pluralNoun={tc('console.domains_1b0ec195')}
                    href={(page) => listHref(search, page)}
                />
            </div>
        </>
    );
}

DomainsOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
