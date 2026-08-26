import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Pill } from '@/ui';

interface StreamRow {
    id: string;
    name: string;
    destination: string;
    endpointUrl: string;
    enabled: boolean;
    href: string;
}

type Props = PageProps<{
    streams: StreamRow[];
    search: string;
    createHref: string;
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function LogStreamsIndex({ streams, search, createHref }: Props) {
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
                description="Mirror this environment's hash-chained audit trail out to your SIEM. Delivery is at-least-once and environment-isolated."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New stream
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search log streams"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {streams.length} {streams.length === 1 ? 'stream' : 'streams'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {streams.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={`No streams match “${search}”`}
                            description="No log stream matches that name. Try a different search."
                        />
                    ) : (
                        <EmptyState
                            icon="audit"
                            title="No log streams yet"
                            description="The audit trail lives here and your security team's tools live somewhere else. A stream mirrors every entry into them as it is written, so an investigation does not start with somebody asking you to export a CSV."
                            steps={[
                                'Pick the destination your SIEM speaks — Splunk, Elastic, Graylog, or plain JSON.',
                                'Paste its endpoint and choose how it authenticates; leave the secret empty for a generated HMAC key.',
                                'Save it: entries start flowing, at least once each, from the next one written.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New stream
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    streams.map((stream, index) => (
                        <Link
                            key={stream.id}
                            href={stream.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < streams.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-medium truncate">{stream.name}</span>
                                    <Badge>{stream.destination}</Badge>
                                </div>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {stream.endpointUrl}
                                </p>
                            </div>

                            <Pill tone={stream.enabled ? 'success' : 'warning'}>
                                {stream.enabled ? 'Delivering' : 'Disabled'}
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
        </>
    );
}

LogStreamsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
