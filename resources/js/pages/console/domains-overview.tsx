import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                        {consoleRich(
                            'console.the_email_domains_your_organizations_have_claimed_a_ver_97773852',
                            {
                                part1: ' ',
                                part2: (
                                    <Link href={ssoHref}>
                                        {consoleT('console.enterprise_sso_0f8d7e8e')}
                                    </Link>
                                ),
                            },
                        )}
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
                        placeholder={consoleUiText('console.search_by_domain_8457a6d8')}
                        aria-label={consoleT('console.search_domains_df3b2794')}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    <output className="sr-only">
                        {consoleRich('console.search_results_found', { count: pagination.total })}
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
                                title={consoleFinalText('console.no_matches_for_part1_42a992b5', {
                                    part1: search,
                                })}
                                description={consoleT(
                                    'console.no_claimed_domain_matches_that_try_part_of_the_domain_w_1212eb45',
                                )}
                            />
                        ) : (
                            <EmptyState
                                icon="building"
                                title={consoleT(
                                    'console.no_organization_has_claimed_a_domain_yet_707e5ab8',
                                )}
                                description={consoleT(
                                    'console.a_domain_is_claimed_by_one_organization_and_proved_with_85c4b058',
                                )}
                                steps={[
                                    consoleRuntimeText(
                                        'console.open_the_organization_and_its_domains_tab_185d9406',
                                    ),
                                    consoleRuntimeText(
                                        'console.add_the_domain_and_publish_the_txt_record_it_shows_or_s_ddb14174',
                                    ),
                                    consoleRuntimeText(
                                        'console.verify_it_then_turn_on_capture_to_route_everyone_on_it_f88a6ce1',
                                    ),
                                ]}
                                actions={
                                    <Link href={organizationsHref} className="btn btn-primary">
                                        {consoleT('console.open_organizations_db4241ab')}
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
                                        ? consoleText('console.verified_4f783840')
                                        : consoleText('console.waiting_for_dns_38500dff')}
                                </Pill>
                                {domain.capture && (
                                    <Badge tone="info">
                                        {consoleT('console.capture_on_f676810d')}
                                    </Badge>
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
                    href={(page) => listHref(search, page)}
                />
            </div>
        </>
    );
}

DomainsOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
