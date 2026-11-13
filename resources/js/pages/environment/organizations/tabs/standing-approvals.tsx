import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime, relativeTime } from '@/lib/time';
import type { PageProps } from '@/types';
import { Badge, Button, ConfirmDelete, EmptyState, Panel, Pill } from '@/ui';

/** One standing approval, as `OrganizationStandingApprovalsController` draws it. */
interface StandingApprovalRow {
    id: string;
    /** The machine client whose deployments it approves. */
    clientId: string;
    /** The app that exchanges for the approval token. */
    appClientId: string;
    /** The app's environment and its kind; never production. */
    environmentRef: string;
    environmentKind: string;
    /** The owner who turned it on (a name, or their id when they are gone). */
    grantedBy: string;
    grantedAt: string;
    lastUsedAt: string | null;
    uses: number;
    active: boolean;
    /** Null once it is off. */
    revokeHref: string | null;
}

type Props = PageProps<{
    approvals: StandingApprovalRow[];
}>;

/**
 * AN ORGANIZATION › STANDING APPROVALS — machine clients whose deployments to one environment
 * of an app run without a person's click. Seen and turned off here; only the organization's
 * owner turns one on, from the app. The header and tabs are the layout's (`../frame.tsx`).
 */
export default function OrganizationStandingApprovals({ approvals }: Props) {
    const { t: consoleT, locale } = useConsoleTranslator();
    const [turningOff, setTurningOff] = useState<StandingApprovalRow | null>(null);

    return (
        <Panel
            title={consoleT('console.standing_approvals_2de7b3d3')}
            description={consoleT(
                'console.machine_clients_whose_deployments_to_one_environment_of_f16e622a',
            )}
            flush={approvals.length > 0}
        >
            {approvals.length === 0 ? (
                <EmptyState
                    icon="key"
                    title={consoleT('console.no_standing_approvals_5bcd78df')}
                    description={consoleT(
                        'console.every_deployment_a_machine_client_proposes_waits_for_a_fb5ab2ba',
                    )}
                />
            ) : (
                <ul>
                    {approvals.map((approval, index) => (
                        <li
                            key={approval.id}
                            className="flex items-start gap-3 flex-wrap px-4 py-3"
                            style={
                                index < approvals.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1 space-y-1.5">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-medium mono break-all min-w-0">
                                        {approval.clientId}
                                    </span>
                                    <Pill tone={approval.active ? 'success' : 'neutral'}>
                                        {approval.active
                                            ? consoleT('console.active_92340695')
                                            : consoleT('console.revoked_f6f738d0')}
                                    </Pill>
                                    <Badge className="mono">{approval.appClientId}</Badge>
                                </div>
                                <p className="text-sm break-words">
                                    {consoleT('console.deployments_to_environment_kind_e497f5fe', {
                                        environment: approval.environmentRef,
                                        kind: approval.environmentKind,
                                    })}
                                </p>
                                <p
                                    className="text-xs break-words"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {consoleT('console.granted_by_name_on_date_5480b63e', {
                                        name: approval.grantedBy,
                                        date: absoluteTime(approval.grantedAt, locale),
                                    })}
                                    {' · '}
                                    {consoleT('console.last_used_830ec7f8')}{' '}
                                    {approval.lastUsedAt === null
                                        ? consoleT('console.never_6300ef80')
                                        : relativeTime(approval.lastUsedAt, locale)}
                                </p>
                            </div>

                            {approval.revokeHref !== null && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    className="shrink-0"
                                    aria-label={consoleT(
                                        'console.turn_off_the_standing_approval_for_client_625cff56',
                                        { client: approval.clientId },
                                    )}
                                    onClick={() => setTurningOff(approval)}
                                >
                                    {consoleT('console.turn_off_06f0e210')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDelete
                open={turningOff !== null}
                onOpenChange={(open) => setTurningOff(open ? turningOff : null)}
                name={turningOff?.clientId ?? ''}
                verb={consoleT('console.turn_off_06f0e210')}
                consequence={consoleT('console.its_deployments_wait_for_a_person_again_dc8d0eb7')}
                onConfirm={() => {
                    const approval = turningOff;
                    setTurningOff(null);

                    if (approval?.revokeHref) {
                        router.delete(approval.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

OrganizationStandingApprovals.layout = (page: React.ReactNode) => (
    <ConsoleLayout>{page}</ConsoleLayout>
);
