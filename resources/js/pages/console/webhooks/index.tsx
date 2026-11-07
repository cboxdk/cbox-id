import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
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
import { type SignatureScheme, signatureSchemeLabels } from './schemes';

interface Endpoint {
    id: string;
    url: string;
    href: string;
    active: boolean;
    /** Null means the ENVIRONMENT owns it and it receives every organization's events. */
    owner: string | null;
    eventCount: number;
    signatureScheme: SignatureScheme;
}

type Props = PageProps<{
    endpoints: Endpoint[];
    pagination: PaginationState;
    search: string;
    createHref: string;
    help: HelpContent;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function WebhooksIndex({
    endpoints,
    pagination,
    search,
    createHref,
    help,
    organizationFilter,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [term, setTerm] = useState(search);
    const first = useRef(true);

    /*
     * Search is a NAVIGATION, not a live filter: the URL carries `q`, so a filtered list
     * can be linked, bookmarked and reloaded, and the browser's back button undoes the
     * search rather than leaving the page.
     *
     * `replace` so a person typing eight characters does not leave eight history entries
     * behind them, and `preserveState` so the field they are typing into is not rebuilt
     * under the cursor on every response.
     */
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = setTimeout(() => {
            router.get(window.location.pathname, term === '' ? {} : { q: term }, {
                replace: true,
                preserveState: true,
                preserveScroll: true,
                only: ['endpoints', 'pagination', 'search'],
            });
        }, 300);

        return () => clearTimeout(timer);
    }, [term]);

    return (
        <>
            <div className="mb-6">
                <PageHeader
                    help={help}
                    description={consoleT(
                        'console.your_endpoints_get_a_signed_message_after_something_hap_04723db4',
                    )}
                    actions={
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                {consoleRich('console._part1_add_endpoint_a0f18f26', {
                                    part1: <Icon name="plus" className="w-4 h-4" />,
                                })}
                            </Link>
                        </Button>
                    }
                />
            </div>

            <div className="space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_url_98cb36da')}
                    aria-label={consoleT('console.search_webhook_endpoints_ac50c850')}
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
                {endpoints.length === 0 ? (
                    term.trim() !== '' ? (
                        <EmptyState
                            icon="webhooks"
                            title={consoleFinalText('console.no_webhooks_match_part1_5bf90fad', {
                                part1: term.trim(),
                            })}
                            description={consoleT(
                                'console.no_endpoint_url_matches_that_search_try_a_different_one_45378b36',
                            )}
                        />
                    ) : (
                        /*
                            An empty page is the first thing an administrator sees on a
                            page they have never used, which makes it the most-read
                            explanation in the console — and "No webhook endpoints yet"
                            tells someone who has not read the guide precisely nothing.
                        */
                        <EmptyState
                            icon="webhooks"
                            equivalent="webhooks.create"
                            title={consoleT('console.nothing_is_being_notified_yet_05d20d59')}
                            help={help}
                            description={consoleT(
                                'console.add_an_endpoint_and_your_own_systems_hear_about_members_263677b5',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.add_your_https_endpoint_and_pick_the_events_it_should_r_ad671375',
                                ),
                                consoleRuntimeText(
                                    'console.store_the_signing_secret_shown_once_and_verify_every_de_04ee9ec1',
                                ),
                                consoleRuntimeText(
                                    'console.reply_2xx_quickly_and_do_the_real_work_in_the_backgroun_90953132',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_add_endpoint_a0f18f26', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    endpoints.map((endpoint, index) => (
                        <Link
                            key={endpoint.id}
                            href={endpoint.href}
                            className="cbx-row"
                            style={
                                index < endpoints.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="block font-medium truncate mono">
                                    {endpoint.url}
                                </span>
                                <div className="mt-1 flex items-center gap-2 flex-wrap">
                                    <Badge>
                                        {endpoint.owner ??
                                            consoleRuntimeText(
                                                'console.all_organizations_e3002b23',
                                            )}
                                    </Badge>
                                    <Badge>{signatureSchemeLabels[endpoint.signatureScheme]}</Badge>
                                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                        {consoleT('console.webhook_subscribed_events_count', {
                                            count: endpoint.eventCount,
                                        })}
                                    </span>
                                </div>
                            </div>

                            <Pill tone={endpoint.active ? 'success' : 'warning'}>
                                {endpoint.active
                                    ? consoleText('console.active_92340695')
                                    : consoleText('console.paused_e159b061')}
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

            <div className="mt-4">
                <Pagination
                    pagination={pagination}
                    href={(page) => {
                        const query = new URLSearchParams();

                        if (search !== '') {
                            query.set('q', search);
                        }

                        query.set('page', String(page));

                        return `${window.location.pathname}?${query.toString()}`;
                    }}
                />
            </div>
        </>
    );
}

WebhooksIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
