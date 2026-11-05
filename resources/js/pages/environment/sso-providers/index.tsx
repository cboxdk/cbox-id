import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Button,
    CopyButton,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface ProviderRow {
    id: string;
    entityId: string;
    active: boolean;
    status: string;
    signedRequests: boolean;
    /** The owning organization's name, or null when every person here may sign in. */
    organization: string | null;
    href: string;
}

type Props = PageProps<{
    providers: ProviderRow[];
    pagination: PaginationState;
    search: string;
    idp: { entityId: string; metadataUrl: string; ssoUrl: string };
    createHref: string;
    /** Authentication › Enterprise SSO, the inbound direction. */
    connectionsHref: string;
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

export default function ServiceProviders({
    providers,
    pagination,
    search,
    idp,
    createHref,
    connectionsHref,
    help,
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
                        {tc('console.applications_that_trust_this_environment_as__7133371a')}{' '}
                        <Link href={connectionsHref}>
                            {tc('console.authentication_enterprise_sso_9aedfbd3')}
                        </Link>
                        .
                    </>
                }
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.add_application_78a2823b')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Panel
                    title={tc('console.your_identity_provider_d8b76e60')}
                    description={tc(
                        'console.give_these_to_the_application_being_register_86b19524',
                    )}
                >
                    <div className="space-y-3">
                        <Coordinate
                            label={tc('console.idp_entity_id_81723d4d')}
                            value={idp.entityId}
                        />
                        <Coordinate
                            label={tc('console.metadata_url_5ead7fa0')}
                            value={idp.metadataUrl}
                        />
                        <Coordinate label={tc('console.sign_on_url_fe0b9e95')} value={idp.ssoUrl} />
                    </div>
                </Panel>
            </div>

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_entity_id_fec39e96')}
                    aria-label={tc('console.search_saml_apps_479a6b15')}
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
                        ? tc('console.application_1fe28920')
                        : tc('console.applications_fd54e5a8')}{' '}
                    {tc('console.found_ddbb3871')}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {providers.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title={tc('console.no_matches_2df01a03')}
                            description={tc(
                                'console.no_applications_match_value0_try_a_different_438c7b0d',
                                { value0: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            equivalent="saml_apps.create"
                            title={tc('console.no_saml_apps_yet_74d41853')}
                            description={tc(
                                'console.register_one_to_let_an_application_sign_its__e20e6b01',
                            )}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.add_application_78a2823b')}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    providers.map((provider, index) => (
                        <Link
                            key={provider.id}
                            href={provider.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < providers.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate mono">
                                    {provider.entityId}
                                </span>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {provider.id}
                                </p>
                            </div>

                            {provider.organization !== null ? (
                                <Pill tone="neutral">{provider.organization}</Pill>
                            ) : (
                                <Pill tone="warning">
                                    {tc('console.environment_wide_cd1204b6')}
                                </Pill>
                            )}

                            {provider.signedRequests && (
                                <Pill tone="info">{tc('console.signed_requests_b6cc80d3')}</Pill>
                            )}

                            <Pill tone={provider.active ? 'success' : 'neutral'}>
                                {provider.active ? tc('console.active_92340695') : provider.status}
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
                noun={tc('console.application_1fe28920')}
                pluralNoun={tc('console.applications_fd54e5a8')}
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

/** One value somebody is about to paste into another tab, with the button that copies it. */
function Coordinate({ label, value }: { label: string; value: string }) {
    const { t: tc } = useTranslator();
    return (
        <div>
            <p className="label">{label}</p>
            <div className="flex items-center gap-2">
                <p
                    className="mono text-xs rounded-lg px-3 py-2 select-all break-all min-w-0 flex-1"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    {value}
                </p>
                <CopyButton
                    value={value}
                    label={tc('console.copy_value0_bf3789b6', { value0: label })}
                />
            </div>
        </div>
    );
}

ServiceProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
