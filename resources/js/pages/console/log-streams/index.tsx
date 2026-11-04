import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Pill } from '@/ui';
import { HEALTH, type StreamHealth } from './fields';

interface StreamRow {
    id: string;
    name: string;
    destination: string;
    endpointUrl: string;
    enabled: boolean;
    health: StreamHealth;
    href: string;
}

type Props = PageProps<{
    streams: StreamRow[];
    search: string;
    createHref: string;
    help: HelpContent;
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function LogStreamsIndex({ streams, search, createHref, help }: Props) {
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
                description={tc('console.mirror_this_environment_s_hash_chained_audit_ce805b40')}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_stream_f6a68001')}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_name_4ae2b333')}
                    aria-label={tc('console.search_log_streams_839086f8')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {streams.length}{' '}
                    {streams.length === 1
                        ? tc('console.stream_dca83e71')
                        : tc('console.streams_d5a08b68')}
                    {tc('console.found_599e25a3')}
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
                            title={tc('console.no_streams_match_value0_3859d3ea', {
                                value0: search,
                            })}
                            description={tc(
                                'console.no_log_stream_matches_that_name_try_a_differ_ac9bbc7c',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="audit"
                            equivalent="log_streams.create"
                            title={tc('console.no_log_streams_yet_3eeab61e')}
                            description={tc(
                                'console.the_audit_trail_lives_here_and_your_security_621820fa',
                            )}
                            steps={[
                                tc('console.pick_where_the_trail_goes_splunk_elastic_gra_e69f3c8f'),
                                tc('console.give_it_the_endpoint_or_bucket_and_the_crede_4cf81bb7'),
                                tc('console.save_it_entries_start_flowing_at_least_once__af7b95b4'),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_stream_f6a68001')}
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

                            <Pill
                                tone={stream.enabled ? HEALTH(tc)[stream.health].tone : 'warning'}
                            >
                                {stream.enabled
                                    ? HEALTH(tc)[stream.health].label
                                    : tc('console.disabled_75081b59')}
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
