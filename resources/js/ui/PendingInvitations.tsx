import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { relativeTime } from '@/lib/time';
import { Badge } from './Badge';
import { Button } from './Button';
import { Dialog } from './Dialog';
import { Panel } from './Panel';

/** `App\Http\Props\Shared\PendingInvitationProps` */
export interface PendingInvitation {
    id: string;
    email: string;
    roleLabel: string;
    /** ISO 8601 both — made relative here, because these pages sit open. */
    expiresAt: string;
    invitedAt: string | null;
    inviterName: string | null;
    appName: string | null;
    /** Null where the viewer may not do it. */
    resendUrl: string | null;
    revokeUrl: string | null;
}

export interface PendingInvitationsProps {
    invitations: PendingInvitation[];
    /** How many are pending in all, when the list shows only the newest. */
    total?: number;
}

/**
 * "INVITED, NOT JOINED YET" — the same list, with the same two answers, wherever somebody
 * can be invited.
 *
 * Only one of the four invite pages could send an invitation again; the others could only
 * withdraw it, so "they never got the mail" had no answer but withdrawing and re-typing the
 * address. Every surface has both now, because every surface is this.
 *
 * WITHDRAWING IS A PLAIN CONFIRM, not type-to-confirm: it destroys nothing a person owns,
 * and inviting them again undoes it.
 */
export function PendingInvitations({ invitations, total }: PendingInvitationsProps) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [withdrawing, setWithdrawing] = useState<PendingInvitation | null>(null);
    const [sending, setSending] = useState<string | null>(null);

    if (invitations.length === 0) {
        return null;
    }

    return (
        <Panel
            title={consoleT('console.invited_not_joined_yet_c4cd0dec')}
            description={
                <>
                    {consoleRich(
                        'console.each_link_works_until_it_expires_or_you_withdraw_it_par_d620d404',
                        {
                            part1: total !== undefined && total > invitations.length && (
                                <>
                                    {consoleRich(
                                        'console._part1_showing_the_part2_most_recent_of_part3_679247ba',
                                        { part1: ' ', part2: invitations.length, part3: total },
                                    )}
                                </>
                            ),
                        },
                    )}
                </>
            }
        >
            <ul className="flex flex-col gap-2">
                {invitations.map((invitation) => (
                    <li
                        key={invitation.id}
                        className="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <div className="min-w-0 flex-1 basis-56">
                            <p className="text-sm flex flex-wrap items-center gap-1">
                                <span className="break-all">{invitation.email}</span>
                                <Badge className="shrink-0">{invitation.roleLabel}</Badge>
                            </p>
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {[
                                    invitation.inviterName !== null
                                        ? consoleNestedText('console.invited_by_part1_5e67e47c', {
                                              part1: invitation.inviterName,
                                          })
                                        : null,
                                    consoleText('console.pending_invitation_dates', {
                                        invited:
                                            invitation.invitedAt === null
                                                ? consoleText('console.recently_59f7a3dd')
                                                : relativeTime(invitation.invitedAt, consoleLocale),
                                        expires: relativeTime(invitation.expiresAt, consoleLocale),
                                    }),
                                    invitation.appName !== null
                                        ? consoleText('console.pending_invitation_application', {
                                              application: invitation.appName,
                                          })
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>

                        {invitation.resendUrl !== null && (
                            <Button
                                size="sm"
                                className="shrink-0"
                                loading={sending === invitation.id}
                                aria-label={consoleFinalText(
                                    'console.send_the_invitation_to_part1_again_06d2bc79',
                                    { part1: invitation.email },
                                )}
                                onClick={() => {
                                    const url = invitation.resendUrl;

                                    if (url === null) {
                                        return;
                                    }

                                    setSending(invitation.id);
                                    router.post(
                                        url,
                                        {},
                                        { preserveScroll: true, onFinish: () => setSending(null) },
                                    );
                                }}
                            >
                                {consoleT('console.send_again_feead925')}
                            </Button>
                        )}

                        {invitation.revokeUrl !== null && (
                            <Button
                                size="sm"
                                className="shrink-0"
                                style={{ color: 'var(--destructive)' }}
                                aria-label={consoleFinalText(
                                    'console.withdraw_the_invitation_to_part1_13c82934',
                                    { part1: invitation.email },
                                )}
                                onClick={() => setWithdrawing(invitation)}
                            >
                                {consoleT('console.withdraw_164546a9')}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog
                open={withdrawing !== null}
                onOpenChange={(open) => !open && setWithdrawing(null)}
                title={consoleFinalText('console.withdraw_the_invitation_to_part1_fafedcae', {
                    part1: withdrawing?.email ?? '',
                })}
                description={consoleT(
                    'console.the_link_they_were_sent_stops_working_immediately_and_a_77389b6a',
                )}
                footer={
                    <>
                        <Button onClick={() => setWithdrawing(null)}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const url = withdrawing?.revokeUrl ?? null;
                                setWithdrawing(null);

                                if (url !== null) {
                                    router.delete(url, { preserveScroll: true });
                                }
                            }}
                        >
                            {consoleT('console.withdraw_164546a9')}
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}
