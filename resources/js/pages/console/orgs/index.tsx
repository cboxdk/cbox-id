import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, EmptyState, Icon, Input, PageHeader, Panel } from '@/ui';

type Organization = {
    slug: string;
    name: string;
    role: string;
    roleLabel: string;
    href: string;
    current: boolean;
};

type Props = PageProps<{ organizations: Organization[] }>;

/**
 * `/orgs`: every organization this person can open, the same list every Cbox app shows.
 *
 * Never a gate: `/` goes straight to the last-used organization, and this is where the
 * organization menu's "All organizations" lands. Searchable, because a consultant can sit
 * in many.
 */
export default function OrganizationsIndex({ organizations }: Props) {
    const { t, rich } = useConsoleTranslator();
    const [query, setQuery] = useState('');

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? organizations
            : organizations.filter(
                  (organization) =>
                      organization.name.toLowerCase().includes(needle) ||
                      organization.slug.toLowerCase().includes(needle),
              );
    }, [organizations, query]);

    return (
        <div style={{ maxWidth: '48rem' }}>
            <PageHeader eyebrow={null} title={t('console.organizations_2730183d')} />

            <div className="mt-6">
                <Panel title={t('console.all_organizations_e3002b23')}>
                    <output className="sr-only">
                        {rich('console.results_on_page', { count: shown.length })}
                    </output>

                    {organizations.length > 1 ? (
                        <div className="mb-3">
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder={t('console.search_organizations_0156eb39')}
                                aria-label={t('console.search_organizations_c1b2693a')}
                            />
                        </div>
                    ) : null}

                    {shown.length === 0 ? (
                        <EmptyState
                            icon="briefcase"
                            title={
                                organizations.length === 0
                                    ? t('console.no_organization_0359b7c6')
                                    : t('console.no_organization_matches_part1_022c2190', {
                                          part1: query.trim(),
                                      })
                            }
                        />
                    ) : (
                        <ul className="divide-y divide-[var(--border)]">
                            {shown.map((organization) => (
                                <li key={organization.slug}>
                                    <Link
                                        href={organization.href}
                                        className="flex min-h-10 items-center gap-3 px-2 py-2 hover:bg-[var(--surface-hover)]"
                                        aria-current={organization.current ? 'page' : undefined}
                                    >
                                        <span className="min-w-0 flex-1 truncate font-medium">
                                            {organization.name}
                                        </span>
                                        <Badge>{organization.roleLabel}</Badge>
                                        {organization.current ? (
                                            <Icon
                                                name="check"
                                                className="w-4 h-4 shrink-0"
                                                aria-label={t('console.current_e0d1b682')}
                                            />
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </div>
    );
}

OrganizationsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
