import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { EmptyState, PageHeader, Pill, Table, Td, TdMono, Th } from '@/ui';

interface RiskEvent {
    id: string;
    when: string;
    action: string;
    outcome: string;
    score: number;
    reasons: string[];
}

type Props = PageProps<{
    events: RiskEvent[];
    /** True on the environment plane with no organization chosen — the whole feed. */
    wholeEnvironment: boolean;
}>;

export default function RiskEvents({ events, wholeEnvironment }: Props) {
    const scope = wholeEnvironment
        ? ', across this environment'
        : ", for this organization's members";

    return (
        <div className="space-y-6">
            <PageHeader
                description={`Sign-ins and requests the platform scored as suspicious enough to flag${scope}. Newest first.`}
            />

            {events.length === 0 ? (
                <EmptyState
                    icon="shield"
                    title="No elevated risk events yet"
                    description="A flagged sign-in appears here as soon as one is scored. Nothing to do until one is."
                />
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table caption="Flagged sign-ins and requests, newest first, with the score and the reasons behind it">
                            <thead>
                                <tr>
                                    <Th>When</Th>
                                    <Th>Action</Th>
                                    <Th>Outcome</Th>
                                    <Th className="text-right">Score</Th>
                                    <Th>Reasons</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {events.map((event) => (
                                    <tr key={event.id}>
                                        <TdMono className="whitespace-nowrap">{event.when}</TdMono>
                                        <Td className="font-medium">{event.action}</Td>
                                        <Td>
                                            <Pill tone="warning" className="capitalize">
                                                {event.outcome}
                                            </Pill>
                                        </Td>
                                        <Td className="text-right tabular-nums mono">
                                            {event.score}
                                        </Td>
                                        {/*
                                            Every reason, joined — the score alone says a
                                            sign-in was suspicious and not why, and "why" is
                                            the only part somebody can act on.
                                        */}
                                        <Td style={{ color: 'var(--muted-foreground)' }}>
                                            {event.reasons.join('; ')}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </div>
            )}
        </div>
    );
}

RiskEvents.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
