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
                        The email domains your organizations have claimed. A verified domain sends
                        everyone with an address on it to that organization’s{' '}
                        <Link href={ssoHref}>Enterprise SSO</Link>. Each one is verified on its
                        organization’s page.
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
                        placeholder="Search by domain"
                        aria-label="Search domains"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                    <output className="sr-only">
                        {pagination.total} {pagination.total === 1 ? 'domain' : 'domains'} found.
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
                                title={`No matches for "${search}"`}
                                description="No claimed domain matches that. Try part of the domain, without the @."
                            />
                        ) : (
                            <EmptyState
                                icon="building"
                                title="No organization has claimed a domain yet"
                                description="A domain is claimed by one organization and proved with a DNS record. Once verified, everyone with an address on it can be sent straight to that organization’s single sign-on."
                                steps={[
                                    'Open the organization, and its Domains tab.',
                                    'Add the domain, and publish the TXT record it shows — or send the organization’s IT admin an Admin Portal link to do it.',
                                    'Verify it, then turn on capture to route everyone on it to their SSO.',
                                ]}
                                actions={
                                    <Link href={organizationsHref} className="btn btn-primary">
                                        Open Organizations
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
                                    {domain.verified ? 'Verified' : 'Waiting for DNS'}
                                </Pill>
                                {domain.capture && <Badge tone="info">Capture on</Badge>}
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
                    noun="domain"
                    href={(page) => listHref(search, page)}
                />
            </div>
        </>
    );
}

DomainsOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
