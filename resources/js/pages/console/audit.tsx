import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime, relativeTime } from '@/lib/time';
import type { HelpContent, PageProps, SimplePagination as SimplePaginationState } from '@/types';
import {
    Badge,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    SimplePagination,
    Table,
    Td,
    TdMono,
    Th,
} from '@/ui';

interface Entry {
    id: string;
    sequence: number;
    action: string;
    phrase: string;
    actorId: string | null;
    actorName: string | null;
    actorType: string;
    targetId: string | null;
    targetName: string | null;
    targetType: string | null;
    facts: string[];
    recordedAt: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    entries: Entry[];
    pagination: SimplePaginationState;
    filters: { action: string; q: string };
    /** No organization is chosen: this is the whole environment's trail. */
    environmentWide: boolean;
}>;

/** The filter state, as a URL. Both boxes and the page number live in one place. */
function filterHref(filters: { action: string; q: string }, page?: number): string {
    const query = new URLSearchParams();

    if (filters.action !== '') {
        query.set('action', filters.action);
    }

    if (filters.q !== '') {
        query.set('q', filters.q);
    }

    if (page !== undefined && page > 1) {
        query.set('page', String(page));
    }

    const search = query.toString();

    return search === '' ? window.location.pathname : `${window.location.pathname}?${search}`;
}

export default function Audit({ help, entries, pagination, filters, environmentWide }: Props) {
    const [action, setAction] = useState(filters.action);
    const [search, setSearch] = useState(filters.q);

    // Debounced, and back to page one: a filter applied while on page four otherwise asks
    // for a page of a list that no longer has one.
    useEffect(() => {
        if (action === filters.action && search === filters.q) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                filterHref({ action, q: search }),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [action, search, filters.action, filters.q]);

    const filtered = filters.action !== '' || filters.q !== '';

    return (
        <>
            <PageHeader
                help={help}
                description="Every change made here: who did what, to what, and when. Hash-chained, so a removed entry shows up."
                actions={
                    <div className="relative w-full sm:w-auto">
                        <Input
                            className="w-full sm:min-w-[16rem]"
                            placeholder="Filter by action…"
                            aria-label="Filter by action"
                            value={action}
                            onChange={(event) => setAction(event.target.value)}
                        />
                    </div>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search action or target"
                    aria-label="Search the activity log"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />

                {/*
                    SC 4.1.3: the trail is replaced on a debounced keystroke with no focus
                    change, so this is the only thing that can report the filter narrowed
                    to nothing.

                    HOW MANY ARE ON SCREEN, not how many exist. The feed is paged without
                    counting the table — deliberately, because counting it is what made the
                    page cost the size of the environment — so there is no total to
                    announce, and inventing one would be a number nobody measured.
                */}
                <output className="sr-only">
                    {entries.length} {entries.length === 1 ? 'entry' : 'entries'} shown.
                </output>

                {environmentWide && (
                    <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                        Every organization in this environment. Choose one above to narrow the trail
                        to it.
                    </p>
                )}
            </div>

            <div className="card overflow-hidden mt-4">
                <div className="overflow-x-auto">
                    <Table caption="Activity log">
                        <thead>
                            <tr>
                                <Th style={{ width: '1%' }} className="hidden sm:table-cell">
                                    Seq
                                </Th>
                                <Th>Action</Th>
                                <Th>Actor</Th>
                                <Th>Target</Th>
                                <Th className="text-right">When</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.length === 0 ? (
                                <tr>
                                    <Td colSpan={5}>
                                        {/*
                                            "Nothing recorded yet" under a filter that
                                            matched nothing is a different fact, and the
                                            wrong one.
                                        */}
                                        {filtered ? (
                                            <EmptyState
                                                icon="search"
                                                title="No matching entries"
                                                description="No entry on this page matches that action or target. Try a broader term, or clear the filter."
                                            />
                                        ) : (
                                            <EmptyState
                                                icon="audit"
                                                title="Nothing recorded yet"
                                                help={help}
                                                description="Every administrative change — members, roles, connections, apps — lands here as it happens, with who did it and when. Nothing to configure; it records itself."
                                            />
                                        )}
                                    </Td>
                                </tr>
                            ) : (
                                entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <TdMono
                                            className="text-xs hidden sm:table-cell"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {entry.sequence}
                                        </TdMono>

                                        <Td className="whitespace-nowrap">
                                            <p className="font-medium">{entry.phrase}</p>
                                            <p
                                                className="text-xs mono"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                {entry.action}
                                            </p>
                                        </Td>

                                        {/*
                                            Name first, id underneath. Somebody reading this
                                            page is answering "who did what to whom" — the
                                            ULID is the evidence, the name is the answer. The
                                            full id stays in the title so it is copyable.
                                        */}
                                        <Td>
                                            {entry.actorName !== null ? (
                                                <>
                                                    <p
                                                        className="text-sm truncate"
                                                        title={entry.actorId ?? undefined}
                                                    >
                                                        {entry.actorName}
                                                    </p>
                                                    <p
                                                        className="text-xs mono truncate"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {entry.actorType}
                                                    </p>
                                                </>
                                            ) : (
                                                <>
                                                    <Badge>{entry.actorType}</Badge>
                                                    {entry.actorId !== null && (
                                                        <span
                                                            className="mono text-xs ml-1"
                                                            style={{ color: 'var(--faint)' }}
                                                            title={entry.actorId}
                                                        >
                                                            {truncateId(entry.actorId)}
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                        </Td>

                                        <Td>
                                            {entry.targetType === null ? (
                                                <span style={{ color: 'var(--faint)' }}>—</span>
                                            ) : entry.targetName !== null ? (
                                                <>
                                                    <p
                                                        className="text-sm truncate"
                                                        title={entry.targetId ?? undefined}
                                                    >
                                                        {entry.targetName}
                                                    </p>
                                                    <p
                                                        className="text-xs truncate"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {entry.targetType}
                                                    </p>
                                                </>
                                            ) : (
                                                <>
                                                    <span
                                                        className="text-sm"
                                                        style={{ color: 'var(--muted-foreground)' }}
                                                    >
                                                        {entry.targetType}
                                                    </span>
                                                    {entry.targetId !== null && (
                                                        <span
                                                            className="mono text-xs ml-1"
                                                            style={{ color: 'var(--faint)' }}
                                                            title={entry.targetId}
                                                        >
                                                            {truncateId(entry.targetId)}
                                                        </span>
                                                    )}
                                                </>
                                            )}

                                            {entry.facts.length > 0 && (
                                                <p
                                                    className="text-xs mt-0.5 truncate"
                                                    style={{ color: 'var(--faint)' }}
                                                    title={entry.facts.join(', ')}
                                                >
                                                    {entry.facts.join(' · ')}
                                                </p>
                                            )}
                                        </Td>

                                        <Td className="text-right whitespace-nowrap">
                                            {entry.recordedAt !== null && (
                                                <time
                                                    className="text-xs"
                                                    style={{ color: 'var(--muted-foreground)' }}
                                                    dateTime={entry.recordedAt}
                                                    title={absoluteTime(entry.recordedAt)}
                                                >
                                                    {relativeTime(entry.recordedAt)}
                                                </time>
                                            )}
                                        </Td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 mt-4">
                <p
                    className="flex items-center gap-1.5 text-xs min-w-0"
                    style={{ color: 'var(--faint)' }}
                >
                    <Icon name="shield" className="w-3.5 h-3.5 shrink-0" />
                    Entries are append-only and hash-chained — any tampering breaks the chain.
                </p>

                <SimplePagination
                    pagination={pagination}
                    noun="entry"
                    pluralNoun="entries"
                    href={(page) => filterHref(filters, page)}
                />
            </div>
        </>
    );
}

/** The first characters of an id, for a cell where the whole thing will not fit. */
function truncateId(id: string): string {
    return id.length > 10 ? `${id.slice(0, 10)}…` : id;
}

Audit.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
