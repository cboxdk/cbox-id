import { useCanManageEnvironment } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const canManage = useCanManageEnvironment();
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
                            'console.applications_that_trust_this_environment_as_their_saml_0e2642cf',
                            {
                                part1: ' ',
                                part2: (
                                    <Link href={connectionsHref}>
                                        {consoleT('console.authentication_enterprise_sso_9aedfbd3')}
                                    </Link>
                                ),
                            },
                        )}
                    </>
                }
                actions={
                    canManage && (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_add_application_4df69500', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="mt-6">
                <Panel
                    title={consoleT('console.your_identity_provider_d8b76e60')}
                    description={consoleT(
                        'console.give_these_to_the_application_being_registered_so_it_ca_86b19524',
                    )}
                >
                    <div className="space-y-3">
                        <Coordinate
                            label={consoleT('console.idp_entity_id_81723d4d')}
                            value={idp.entityId}
                        />
                        <Coordinate
                            label={consoleT('console.metadata_url_5ead7fa0')}
                            value={idp.metadataUrl}
                        />
                        <Coordinate
                            label={consoleT('console.sign_on_url_fe0b9e95')}
                            value={idp.ssoUrl}
                        />
                    </div>
                </Panel>
            </div>

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_entity_id_fec39e96')}
                    aria-label={consoleT('console.search_saml_apps_479a6b15')}
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
                {providers.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title={consoleT('console.no_matches_2df01a03')}
                            description={consoleFinalText(
                                'console.no_applications_match_part1_try_a_different_entity_id_386ed821',
                                { part1: search },
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            equivalent="saml_apps.create"
                            title={consoleT('console.no_saml_apps_yet_74d41853')}
                            description={consoleT(
                                'console.register_one_to_let_an_application_sign_its_users_in_wi_e20e6b01',
                            )}
                            actions={
                                canManage && (
                                    <Button asChild variant="primary">
                                        <Link href={createHref}>
                                            {consoleRich(
                                                'console._part1_add_application_4df69500',
                                                {
                                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                                },
                                            )}
                                        </Link>
                                    </Button>
                                )
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
                                    {consoleT('console.environment_wide_cd1204b6')}
                                </Pill>
                            )}

                            {provider.signedRequests && (
                                <Pill tone="info">
                                    {consoleT('console.signed_requests_b6cc80d3')}
                                </Pill>
                            )}

                            <Pill tone={provider.active ? 'success' : 'neutral'}>
                                {provider.active
                                    ? consoleText('console.active_92340695')
                                    : provider.status}
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

            <Pagination pagination={pagination} href={(page) => listHref(search, page)} />
        </>
    );
}

/** One value somebody is about to paste into another tab, with the button that copies it. */
function Coordinate({ label, value }: { label: string; value: string }) {
    const { t: consoleFinalText } = useConsoleTranslator();

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
                    label={consoleFinalText('console.copy_part1_99ee9514', { part1: label })}
                />
            </div>
        </div>
    );
}

ServiceProviders.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
