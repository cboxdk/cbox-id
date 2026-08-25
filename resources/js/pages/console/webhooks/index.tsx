import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Pagination, Pill } from '@/ui';

interface Endpoint {
    id: string;
    url: string;
    href: string;
    active: boolean;
    /** Null means the ENVIRONMENT owns it and it receives every organization's events. */
    owner: string | null;
    eventCount: number;
}

type Props = PageProps<{
    endpoints: Endpoint[];
    pagination: PaginationState;
    search: string;
    createHref: string;
    help: HelpContent;
}>;

export default function WebhooksIndex({ endpoints, pagination, search, createHref, help }: Props) {
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
            router.get(
                window.location.pathname,
                term === '' ? {} : { q: term },
                { replace: true, preserveState: true, preserveScroll: true, only: ['endpoints', 'pagination', 'search'] },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term]);

    return (
        <>
            <div className="mb-6">
                <PageHeader
                    help={help}
                    description="Your endpoints get a signed message after something happens here, so your systems can react without asking us every minute."
                    actions={
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                Add endpoint
                            </Link>
                        </Button>
                    }
                />
            </div>

            <div>
                <Input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by URL"
                    aria-label="Search webhook endpoints"
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'endpoint' : 'endpoints'} found.
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
                            title={`No webhooks match “${term.trim()}”`}
                            description="No endpoint URL matches that search. Try a different one."
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
                            title="Nothing is being notified yet"
                            help={help}
                            description="Add an endpoint and your own systems hear about members joining, roles changing and sign-ins failing as it happens — no polling, no nightly export."
                            steps={[
                                'Add your HTTPS endpoint and pick the events it should receive.',
                                'Store the signing secret shown once, and verify every delivery against it.',
                                'Reply 2xx quickly and do the real work in the background — deliveries are retried, so handle repeats safely.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        Add endpoint
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
                                    <Badge>{endpoint.owner ?? 'All organizations'}</Badge>
                                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                        {endpoint.eventCount}{' '}
                                        {endpoint.eventCount === 1 ? 'event' : 'events'} subscribed
                                    </span>
                                </div>
                            </div>

                            <Pill tone={endpoint.active ? 'success' : 'warning'}>
                                {endpoint.active ? 'Active' : 'Paused'}
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
                    noun="endpoint"
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
