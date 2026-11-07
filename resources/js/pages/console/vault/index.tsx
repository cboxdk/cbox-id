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
                description={consoleT(
                    'console.api_keys_your_apps_and_agents_present_to_other_services_ab18c348',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_secret_384aa43d', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            {environmentWide && (
                // Said out loud: "the vault" is two different collections, and which one this
                // is should not have to be inferred. An organization's own is on its page —
                // the Organization chip below goes there.
                <p className="mt-4 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT(
                        'console.showing_this_environment_s_own_secrets_an_organization_d072355c',
                    )}
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
                    placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                    aria-label={consoleT('console.search_secrets_b5814f05')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {consoleRich('console.search_results_found', { count: pagination.total })}
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
                            title={consoleT('console.no_matching_secrets_a336141e')}
                            description={consoleFinalText(
                                'console.no_secret_matches_part1_try_a_different_name_ae25c808',
                                { part1: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            equivalent="token_vault.secrets.create"
                            title={consoleT('console.no_secrets_stored_yet_4b7ede4d')}
                            help={help}
                            description={consoleT(
                                'console.every_api_key_sitting_in_an_app_s_config_or_environment_975161a8',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.store_the_provider_s_api_key_you_will_not_see_it_again_55df55b4',
                                ),
                                consoleRuntimeText(
                                    'console.grant_the_specific_apps_that_may_use_it_nothing_else_ca_7554df68',
                                ),
                                consoleRuntimeText(
                                    'console.rotate_it_here_when_the_provider_issues_a_new_one_the_a_d8f5964e',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_new_secret_384aa43d', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
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
                                    ? consoleText('console.revoked_f6f738d0')
                                    : secret.status === 'expired'
                                      ? consoleText('console.expired_424a2551')
                                      : consoleText('console.active_92340695')}
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
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

VaultIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
