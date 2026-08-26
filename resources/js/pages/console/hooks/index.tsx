import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Pill,
    Tooltip,
} from '@/ui';

interface HookRow {
    id: string;
    url: string;
    point: string;
    pointDescription: string;
    /** The organization it belongs to, or null when the environment owns it. */
    owner: string | null;
    active: boolean;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    hooks: HookRow[];
    pagination: PaginationState;
    search: string;
    createHref: string;
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

export default function HooksIndex({ help, hooks, pagination, search, createHref }: Props) {
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
                description="Your endpoint is called in the middle of an operation and its answer changes the outcome — add data to a token, or refuse the sign-in."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            Register endpoint
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by URL"
                    aria-label="Search inline hooks"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'hook' : 'hooks'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {hooks.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No hooks match “${search}”`}
                            description="No endpoint URL matches that search. Try a different one."
                        />
                    ) : (
                        <EmptyState
                            icon="webhooks"
                            title="No inline hooks registered"
                            help={help}
                            description="Most integrations want Webhooks instead — those run after the fact and cannot hold anything up. Reach for an inline hook only when your own system must have a say while a sign-in or token is being issued."
                            steps={[
                                'Register the endpoint and choose the hook point it answers at.',
                                'Verify the signature on every call, and answer within the timeout — this runs while someone waits at the sign-in screen.',
                                'Fail open or closed deliberately: decide now what should happen when your endpoint is down.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        Register endpoint
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    hooks.map((hook, index) => (
                        <Link
                            key={hook.id}
                            href={hook.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < hooks.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    {/*
                                        The hook point NAMES the moment your endpoint is
                                        called, and the whole page turns on it — so what it
                                        means is reachable here rather than only on the
                                        detail page.
                                    */}
                                    <Tooltip content={hook.pointDescription}>
                                        <span>
                                            <Badge>{hook.point}</Badge>
                                        </span>
                                    </Tooltip>
                                    <Badge>{hook.owner ?? 'All organizations'}</Badge>
                                </div>
                                <p
                                    className="mt-1 text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {hook.url}
                                </p>
                            </div>

                            <Pill tone={hook.active ? 'success' : 'warning'}>
                                {hook.active ? 'Active' : 'Paused'}
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
                noun="hook"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

HooksIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
