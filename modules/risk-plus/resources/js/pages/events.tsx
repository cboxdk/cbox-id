import { useTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
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
    help: HelpContent;
}>;

export default function RiskEvents({ events, wholeEnvironment, help }: Props) {
    const { t: tc } = useTranslator();

    const scope = wholeEnvironment
        ? tc('console.across_this_environment_92c427a7')
        : tc('console.for_this_organization_s_members_fcaf6d7e');

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={tc('console.sign_ins_and_requests_the_platform_scored_as_091fe5e6', {
                    value0: scope,
                })}
            />

            {events.length === 0 ? (
                <EmptyState
                    icon="shield"
                    title={tc('console.no_elevated_risk_events_yet_75ea2b81')}
                    description={tc(
                        'console.a_flagged_sign_in_appears_here_as_soon_as_on_234914f4',
                    )}
                />
            ) : (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table
                            caption={tc(
                                'console.flagged_sign_ins_and_requests_newest_first_w_c0c618d5',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{tc('console.when_cf9c7aa2')}</Th>
                                    <Th>{tc('console.action_64cff131')}</Th>
                                    <Th>{tc('console.outcome_4e80abb5')}</Th>
                                    <Th className="text-right">{tc('console.score_38e5a46c')}</Th>
                                    <Th>{tc('console.reasons_72ed245d')}</Th>
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
