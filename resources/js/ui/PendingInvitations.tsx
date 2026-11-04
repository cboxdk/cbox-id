import { useUiTranslator } from '@/i18n/ui';
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
    const { locale } = useUiTranslator();

    const { t: tc } = useUiTranslator();

    const [withdrawing, setWithdrawing] = useState<PendingInvitation | null>(null);
    const [sending, setSending] = useState<string | null>(null);

    if (invitations.length === 0) {
        return null;
    }

    return (
        <Panel
            title={tc('console.invited_not_joined_yet_c4cd0dec', 'Invited, not joined yet')}
            description={
                <>
                    {tc(
                        'console.each_link_works_until_it_expires_or_you_with_c5668c67',
                        'Each link works until it expires or you withdraw it.',
                    )}
                    {total !== undefined && total > invitations.length && (
                        <>
                            {' '}
                            {tc('console.showing_the_d20ab9b1', 'Showing the ')}
                            {invitations.length}
                            {tc('console.most_recent_of_250cb204', ' most recent of ')}
                            {total}.
                        </>
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
                        <div className="min-w-0 flex-1">
                            <p className="text-sm truncate">
                                {invitation.email}{' '}
                                <Badge className="ml-1">{invitation.roleLabel}</Badge>
                            </p>
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {invitation.inviterName !== null
                                    ? `invited by ${invitation.inviterName} `
                                    : tc('console.invited_98b2a312', 'invited ')}
                                {invitation.invitedAt === null
                                    ? tc('console.recently_59f7a3dd', 'recently')
                                    : relativeTime(invitation.invitedAt, locale)}
                                {' · '}
                                {tc('console.expires_22206a25', 'expires ')}
                                {relativeTime(invitation.expiresAt, locale)}
                                {invitation.appName !== null && ` · for ${invitation.appName}`}
                            </p>
                        </div>

                        {invitation.resendUrl !== null && (
                            <Button
                                size="sm"
                                className="shrink-0"
                                loading={sending === invitation.id}
                                aria-label={`Send the invitation to ${invitation.email} again`}
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
                                {tc('console.send_again_feead925', 'Send again')}
                            </Button>
                        )}

                        {invitation.revokeUrl !== null && (
                            <Button
                                size="sm"
                                className="shrink-0"
                                style={{ color: 'var(--destructive)' }}
                                aria-label={`Withdraw the invitation to ${invitation.email}`}
                                onClick={() => setWithdrawing(invitation)}
                            >
                                {tc('console.withdraw_164546a9', 'Withdraw')}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog
                open={withdrawing !== null}
                onOpenChange={(open) => !open && setWithdrawing(null)}
                title={`Withdraw the invitation to ${withdrawing?.email ?? ''}?`}
                description={tc(
                    'console.the_link_they_were_sent_stops_working_immedi_77389b6a',
                    'The link they were sent stops working immediately, and any roles it carried go with it. You can invite them again afterwards.',
                )}
                footer={
                    <>
                        <Button onClick={() => setWithdrawing(null)}>
                            {tc('console.cancel_19766ed6', 'Cancel')}
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
                            {tc('console.withdraw_164546a9', 'Withdraw')}
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}
