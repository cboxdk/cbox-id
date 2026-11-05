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
    Button,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pagination,
    Pill,
} from '@/ui';

interface SecretRow {
    id: string;
    name: string;
    provider: string;
    scope: string;
    status: 'active' | 'expired' | 'revoked';
    revoked: boolean;
    rotatedAt: string | null;
    expiresAt: string | null;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    secrets: SecretRow[];
    pagination: PaginationState;
    search: string;
    /** True when this is the environment's OWN set, not a wider view of the tenants'. */
    environmentWide: boolean;
    createHref: string;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

/** Revoked is permanent; expired is a date that has passed. The next move differs. */
export function statusTone(status: SecretRow['status']): 'success' | 'warning' | 'destructive' {
    if (status === 'revoked') {
        return 'destructive';
    }

    return status === 'expired' ? 'warning' : 'success';
}

export default function VaultIndex({
    help,
    secrets,
    pagination,
    search,
    environmentWide,
    createHref,
    organizationFilter,
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
                description={tc('console.api_keys_your_apps_and_agents_present_to_oth_ab18c348')}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_secret_1088a1c0')}
                        </Link>
                    </Button>
                }
            />

            {environmentWide && (
                // Said out loud: "the vault" is two different collections, and which one this
                // is should not have to be inferred. An organization's own is on its page —
                // the Organization chip below goes there.
                <p className="mt-4 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.showing_this_environment_s_own_secrets_an_or_d072355c')}
                </p>
            )}

            <div className="mt-6 space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_name_4ae2b333')}
                    aria-label={tc('console.search_secrets_b5814f05')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total}{' '}
                    {pagination.total === 1
                        ? tc('console.secret_2bb80d53')
                        : tc('console.secrets_20e8cdb5')}
                    {tc('console.found_599e25a3')}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {secrets.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title={tc('console.no_matching_secrets_a336141e')}
                            description={tc(
                                'console.no_secret_matches_value0_try_a_different_nam_48d63188',
                                { value0: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            equivalent="token_vault.secrets.create"
                            title={tc('console.no_secrets_stored_yet_4b7ede4d')}
                            help={help}
                            description={tc(
                                'console.every_api_key_sitting_in_an_app_s_config_or__975161a8',
                            )}
                            steps={[
                                tc('console.store_the_provider_s_api_key_you_will_not_se_55df55b4'),
                                tc('console.grant_the_specific_apps_that_may_use_it_noth_7554df68'),
                                tc('console.rotate_it_here_when_the_provider_issues_a_ne_d8f5964e'),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_secret_1088a1c0')}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    secrets.map((secret, index) => (
                        <Link
                            key={secret.id}
                            href={secret.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < secrets.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate">{secret.name}</span>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {secret.provider}
                                </p>
                            </div>

                            <Badge>{secret.scope}</Badge>

                            <Pill tone={statusTone(secret.status)}>
                                {secret.status === 'revoked'
                                    ? tc('console.revoked_f6f738d0')
                                    : secret.status === 'expired'
                                      ? tc('console.expired_424a2551')
                                      : tc('console.active_92340695')}
                            </Pill>

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
                noun={tc('console.secret_2bb80d53')}
                pluralNoun={tc('console.secrets_20e8cdb5')}
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

VaultIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
